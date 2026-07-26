<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garante um identificador de correlacao por requisicao.
 *
 * O mesmo id acompanha a mensagem ate o worker e volta nos eventos de
 * resultado, permitindo rastrear um upload de ponta a ponta nos logs.
 */
class CorrelationId
{
    public const HEADER = 'X-Correlation-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header(self::HEADER) ?: (string) Str::uuid();

        $request->attributes->set('correlation_id', $correlationId);

        // Injeta o id em todos os logs emitidos durante esta requisicao.
        Log::withContext(['correlation_id' => $correlationId]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
