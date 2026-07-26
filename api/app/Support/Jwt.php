<?php

declare(strict_types=1);

namespace App\Support;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT as FirebaseJwt;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Emissao e validacao de tokens JWT.
 *
 * HS256 com segredo compartilhado, mantendo a mesma estrategia adotada na Fase 3:
 * qualquer servico que conheca o segredo valida o token localmente, sem
 * round-trip para a API.
 */
class Jwt
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function issue(int $userId, array $claims = []): string
    {
        $agora = time();
        $config = config('fiapx.jwt');

        $payload = array_merge($claims, [
            'iss' => $config['issuer'],
            'sub' => (string) $userId,
            'iat' => $agora,
            'nbf' => $agora,
            'exp' => $agora + ($config['ttl'] * 60),
            'jti' => (string) Str::uuid(),
        ]);

        return FirebaseJwt::encode($payload, $this->secret(), $config['algo']);
    }

    /**
     * Devolve os claims do token ou null se ele for invalido/expirado.
     *
     * @return array<string, mixed>|null
     */
    public function decode(string $token): ?array
    {
        try {
            $decoded = FirebaseJwt::decode(
                $token,
                new Key($this->secret(), config('fiapx.jwt.algo'))
            );

            return (array) $decoded;
        } catch (ExpiredException|SignatureInvalidException|UnexpectedValueException) {
            // Token invalido nao e excecao de sistema: e uma resposta 401.
            return null;
        }
    }

    public function ttlSeconds(): int
    {
        return (int) config('fiapx.jwt.ttl') * 60;
    }

    private function secret(): string
    {
        $secret = (string) config('fiapx.jwt.secret');

        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET nao configurado.');
        }

        return $secret;
    }
}
