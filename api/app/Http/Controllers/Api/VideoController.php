<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadVideoRequest;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Services\VideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recepcao de videos, consulta de status e liberacao do download do ZIP.
 */
class VideoController extends Controller
{
    public function __construct(private readonly VideoService $videos) {}

    /**
     * Listagem de status dos videos do usuario autenticado.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', Video::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginado = $this->videos->listForUser(
            $request->user(),
            $request->query('status'),
            (int) $request->query('per_page', 15),
        );

        return VideoResource::collection($paginado);
    }

    /**
     * Recebe o video e devolve 202: o processamento acontece de forma assincrona.
     */
    public function store(UploadVideoRequest $request): JsonResponse
    {
        $video = $this->videos->upload(
            $request->user(),
            $request->file('video'),
            (string) $request->attributes->get('correlation_id'),
        );

        return (new VideoResource($video))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $video = $this->encontrar($request, $id);

        return (new VideoResource($video))->response();
    }

    /**
     * Devolve um link temporario para o ZIP dos frames.
     */
    public function download(Request $request, string $id): JsonResponse
    {
        $video = $this->encontrar($request, $id);

        if (! $video->isDownloadable()) {
            return response()->json([
                'message' => 'O ZIP ainda nao esta disponivel.',
                'status' => $video->status,
            ], Response::HTTP_CONFLICT);
        }

        // JSON_UNESCAPED_SLASHES mantem a URL legivel ("http://" em vez de
        // "http:\/\/"). Sem isso, clientes que extraem o campo sem um parser
        // de JSON completo acabam tentando baixar de um endereco invalido.
        return response()->json([
            'download_url' => $this->videos->downloadUrl($video),
            'expires_in' => (int) config('fiapx.storage.download_ttl_minutes') * 60,
        ], Response::HTTP_OK, [], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Busca o video restrito ao dono. Um id de outro usuario resulta em 404,
     * sem revelar que o recurso existe.
     */
    private function encontrar(Request $request, string $id): Video
    {
        return Video::query()
            ->ofUser($request->user()->id)
            ->findOrFail($id);
    }
}
