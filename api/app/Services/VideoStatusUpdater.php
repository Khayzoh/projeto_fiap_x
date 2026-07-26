<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\VideoProcessingFailedMail;
use App\Models\Video;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Aplica no banco os eventos de resultado publicados pelo worker
 * e notifica o usuario quando o processamento falha.
 */
class VideoStatusUpdater
{
    /**
     * Registra a conclusao de um processamento.
     *
     * @param  array<string, mixed>  $payload
     */
    public function markCompleted(array $payload): bool
    {
        $video = $this->find($payload['video_id'] ?? null);

        if ($video === null) {
            return false;
        }

        // Reentrega da mesma mensagem nao deve sobrescrever um estado ja final.
        if ($video->status === Video::STATUS_COMPLETED) {
            return true;
        }

        $video->fill([
            'status' => Video::STATUS_COMPLETED,
            'zip_object_key' => $payload['zip_object_key'] ?? null,
            'frame_count' => $payload['frame_count'] ?? null,
            'zip_size_bytes' => $payload['zip_size_bytes'] ?? null,
            'error_message' => null,
            'processed_at' => $this->parseDate($payload['processed_at'] ?? null),
        ])->save();

        Log::info('video processado com sucesso', [
            'video_id' => $video->id,
            'frame_count' => $video->frame_count,
        ]);

        return true;
    }

    /**
     * Registra a falha definitiva e dispara a notificacao ao usuario.
     *
     * @param  array<string, mixed>  $payload
     */
    public function markFailed(array $payload): bool
    {
        $video = $this->find($payload['video_id'] ?? null);

        if ($video === null) {
            return false;
        }

        if ($video->status === Video::STATUS_FAILED) {
            // Ja notificado: nao reenviar e-mail em caso de reentrega.
            return true;
        }

        $video->fill([
            'status' => Video::STATUS_FAILED,
            'error_message' => $payload['reason'] ?? 'Falha desconhecida no processamento.',
            'attempts' => $payload['attempts'] ?? $video->attempts,
            'processed_at' => $this->parseDate($payload['failed_at'] ?? null),
        ])->save();

        $this->notifyFailure($video);

        Log::error('falha no processamento de video', [
            'video_id' => $video->id,
            'user_id' => $video->user_id,
            'reason' => $video->error_message,
            'attempts' => $video->attempts,
        ]);

        return true;
    }

    /**
     * Envia o e-mail de falha.
     *
     * Uma falha no envio nao pode derrubar o consumo: o status ja foi gravado,
     * e reprocessar a mensagem so geraria e-mail duplicado.
     */
    private function notifyFailure(Video $video): void
    {
        $usuario = $video->user;

        if ($usuario === null || blank($usuario->email)) {
            return;
        }

        try {
            Mail::to($usuario->email)->send(new VideoProcessingFailedMail($video));
        } catch (\Throwable $e) {
            Log::error('nao foi possivel enviar a notificacao de falha', [
                'video_id' => $video->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function find(?string $videoId): ?Video
    {
        if (blank($videoId)) {
            return null;
        }

        return Video::query()->with('user')->find($videoId);
    }

    private function parseDate(?string $valor): Carbon
    {
        if (blank($valor)) {
            return now();
        }

        try {
            return Carbon::parse($valor);
        } catch (\Throwable) {
            return now();
        }
    }
}
