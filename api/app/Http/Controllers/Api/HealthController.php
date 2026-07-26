<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Probes de saude e metricas no formato do Prometheus.
 */
class HealthController extends Controller
{
    /**
     * Liveness: responde enquanto o processo estiver vivo.
     * Nao consulta dependencias — um banco fora do ar nao deve reiniciar o pod.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'fiapx-api',
            'time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Readiness: so recebe trafego quem consegue falar com as dependencias.
     */
    public function ready(): JsonResponse
    {
        $checagens = [
            'database' => $this->verificar(fn () => DB::connection()->getPdo() !== null),
            'redis' => $this->verificar(fn () => Redis::connection()->ping() !== null),
        ];

        $saudavel = ! in_array(false, $checagens, true);

        return response()->json([
            'status' => $saudavel ? 'ready' : 'degraded',
            'checks' => $checagens,
        ], $saudavel ? 200 : 503);
    }

    /**
     * Metricas de negocio expostas para o Prometheus.
     *
     * Complementam as metricas tecnicas do worker: aqui esta a fila do ponto
     * de vista do usuario (quantos videos em cada estado).
     */
    public function metrics(): Response
    {
        $porStatus = Video::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $linhas = [
            '# HELP fiapx_videos_total Total de videos por status.',
            '# TYPE fiapx_videos_total gauge',
        ];

        foreach (Video::STATUSES as $status) {
            $total = (int) ($porStatus[$status] ?? 0);
            $linhas[] = sprintf('fiapx_videos_total{status="%s"} %d', $status, $total);
        }

        $linhas[] = '# HELP fiapx_api_up Indica que a API esta respondendo.';
        $linhas[] = '# TYPE fiapx_api_up gauge';
        $linhas[] = 'fiapx_api_up 1';

        return response(implode("\n", $linhas)."\n", 200, [
            'Content-Type' => 'text/plain; version=0.0.4',
        ]);
    }

    private function verificar(callable $checagem): bool
    {
        try {
            return (bool) $checagem();
        } catch (\Throwable) {
            return false;
        }
    }
}
