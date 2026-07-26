<?php

declare(strict_types=1);

namespace App\Services;

use App\Messaging\VideoEventPublisher;
use App\Models\User;
use App\Models\Video;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Regras de aplicacao do fluxo de video: receber, enfileirar, listar e liberar download.
 */
class VideoService
{
    public function __construct(private readonly VideoEventPublisher $publisher) {}

    /**
     * Recebe o arquivo, guarda no storage de objetos e enfileira o processamento.
     *
     * O upload responde imediatamente com 202: quem processa e o worker.
     * E isso que permite atender varios videos ao mesmo tempo sem prender
     * um processo de PHP por minutos, como fazia o projeto base.
     */
    public function upload(User $user, UploadedFile $file, string $correlationId): Video
    {
        $videoId = (string) Str::uuid();
        $extensao = strtolower($file->getClientOriginalExtension());
        $objectKey = "videos/{$videoId}/original.{$extensao}";

        // O arquivo vai para o storage antes do registro: se o upload falhar,
        // nao sobra linha orfa no banco apontando para um objeto inexistente.
        $stream = fopen($file->getRealPath(), 'rb');

        if ($stream === false) {
            throw new RuntimeException('Nao foi possivel ler o arquivo enviado.');
        }

        try {
            $gravou = Storage::disk(config('fiapx.storage.disk'))
                ->put($objectKey, $stream, ['ContentType' => $file->getMimeType()]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($gravou === false) {
            throw new RuntimeException('Falha ao gravar o video no storage.');
        }

        $video = new Video([
            'id' => $videoId,
            'user_id' => $user->id,
            'original_filename' => $file->getClientOriginalName(),
            'object_key' => $objectKey,
            'status' => Video::STATUS_PENDING,
            'size_bytes' => $file->getSize(),
            'correlation_id' => $correlationId,
        ]);
        $video->id = $videoId;

        // A publicacao acontece dentro da transacao: se o broker estiver fora,
        // o registro nao e confirmado e o cliente recebe erro, em vez de ficar
        // com um video eternamente "PENDING" que ninguem vai processar.
        DB::transaction(function () use ($video, $correlationId): void {
            $video->save();
            $this->publisher->publishUploaded($video, $correlationId);
        });

        Log::info('video recebido e enfileirado', [
            'video_id' => $video->id,
            'user_id' => $user->id,
            'size_bytes' => $video->size_bytes,
            'correlation_id' => $correlationId,
        ]);

        return $video;
    }

    /**
     * Lista os videos do usuario, mais recentes primeiro.
     */
    public function listForUser(User $user, ?string $status = null, int $perPage = 15): LengthAwarePaginator
    {
        return Video::query()
            ->ofUser($user->id)
            ->withStatus($status)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Gera um link temporario para o ZIP.
     *
     * O download nao passa pela API: o cliente busca direto no storage, o que
     * evita prender um worker de PHP transferindo centenas de megabytes.
     */
    public function downloadUrl(Video $video): string
    {
        if (! $video->isDownloadable()) {
            throw new RuntimeException('Video ainda nao possui ZIP disponivel.');
        }

        $minutos = (int) config('fiapx.storage.download_ttl_minutes');
        $nomeArquivo = pathinfo($video->original_filename, PATHINFO_FILENAME).'_frames.zip';

        return Storage::disk(config('fiapx.storage.public_disk'))->temporaryUrl(
            $video->zip_object_key,
            now()->addMinutes($minutos),
            ['ResponseContentDisposition' => 'attachment; filename="'.$nomeArquivo.'"']
        );
    }
}
