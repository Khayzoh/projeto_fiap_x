<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Jwt;
use Firebase\JWT\JWT as FirebaseJwt;
use Tests\TestCase;

class JwtTest extends TestCase
{
    private Jwt $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jwt = new Jwt;
    }

    public function test_emite_token_decodificavel(): void
    {
        $token = $this->jwt->issue(42, ['email' => 'kaue@fiapx.test']);

        $claims = $this->jwt->decode($token);

        $this->assertNotNull($claims);
        $this->assertSame('42', $claims['sub']);
        $this->assertSame('kaue@fiapx.test', $claims['email']);
        $this->assertSame(config('fiapx.jwt.issuer'), $claims['iss']);
    }

    public function test_token_carrega_expiracao_configurada(): void
    {
        $token = $this->jwt->issue(1);
        $claims = $this->jwt->decode($token);

        $janela = $claims['exp'] - $claims['iat'];

        $this->assertSame(config('fiapx.jwt.ttl') * 60, $janela);
    }

    public function test_cada_token_tem_identificador_proprio(): void
    {
        $primeiro = $this->jwt->decode($this->jwt->issue(1));
        $segundo = $this->jwt->decode($this->jwt->issue(1));

        $this->assertNotSame($primeiro['jti'], $segundo['jti']);
    }

    public function test_recusa_token_expirado(): void
    {
        // Emitido e expirado no passado.
        $expirado = FirebaseJwt::encode([
            'iss' => config('fiapx.jwt.issuer'),
            'sub' => '1',
            'iat' => time() - 7200,
            'exp' => time() - 3600,
        ], config('fiapx.jwt.secret'), config('fiapx.jwt.algo'));

        $this->assertNull($this->jwt->decode($expirado));
    }

    public function test_recusa_token_assinado_com_outro_segredo(): void
    {
        $forjado = FirebaseJwt::encode([
            'sub' => '1',
            'exp' => time() + 3600,
        ], 'segredo-do-atacante-com-tamanho-suficiente-para-hs256', 'HS256');

        $this->assertNull($this->jwt->decode($forjado));
    }

    public function test_recusa_token_malformado(): void
    {
        $this->assertNull($this->jwt->decode('isso-nao-e-um-jwt'));
    }

    public function test_ttl_em_segundos(): void
    {
        $this->assertSame(config('fiapx.jwt.ttl') * 60, $this->jwt->ttlSeconds());
    }
}
