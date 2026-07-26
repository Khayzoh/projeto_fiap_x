<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Jwt;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege as rotas exigindo um Bearer token valido.
 *
 * Atende ao requisito "o sistema deve ser protegido por usuario e senha":
 * o token so e emitido apos a validacao das credenciais em /api/auth/login.
 */
class JwtAuthenticate
{
    public function __construct(private readonly Jwt $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return $this->naoAutorizado('Token de autenticacao ausente.');
        }

        $claims = $this->jwt->decode($token);

        if ($claims === null || ! isset($claims['sub'])) {
            return $this->naoAutorizado('Token invalido ou expirado.');
        }

        $user = User::find((int) $claims['sub']);

        if ($user === null) {
            // Token assinado corretamente, mas o usuario foi removido depois da emissao.
            return $this->naoAutorizado('Usuario do token nao existe mais.');
        }

        // Deixa o usuario disponivel via auth()->user() e $request->user().
        auth()->setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    private function naoAutorizado(string $mensagem): JsonResponse
    {
        return response()->json([
            'message' => $mensagem,
        ], Response::HTTP_UNAUTHORIZED);
    }
}
