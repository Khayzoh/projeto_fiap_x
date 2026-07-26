<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Jwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_cadastra_usuario_e_devolve_token(): void
    {
        $resposta = $this->postJson('/api/auth/register', [
            'name' => 'Kaue',
            'email' => 'kaue@fiapx.test',
            'password' => 'senhaSegura1',
            'password_confirmation' => 'senhaSegura1',
        ]);

        $resposta->assertCreated()
            ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token', 'token_type', 'expires_in']);

        $this->assertDatabaseHas('users', ['email' => 'kaue@fiapx.test']);
    }

    public function test_nao_devolve_a_senha_na_resposta(): void
    {
        $resposta = $this->postJson('/api/auth/register', [
            'name' => 'Kaue',
            'email' => 'kaue@fiapx.test',
            'password' => 'senhaSegura1',
            'password_confirmation' => 'senhaSegura1',
        ]);

        $resposta->assertJsonMissingPath('user.password');
    }

    public function test_recusa_email_duplicado(): void
    {
        User::factory()->create(['email' => 'kaue@fiapx.test']);

        $this->postJson('/api/auth/register', [
            'name' => 'Outro',
            'email' => 'kaue@fiapx.test',
            'password' => 'senhaSegura1',
            'password_confirmation' => 'senhaSegura1',
        ])->assertUnprocessable()->assertJsonValidationErrorFor('email');
    }

    public function test_recusa_senha_fraca(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Kaue',
            'email' => 'kaue@fiapx.test',
            'password' => '123',
            'password_confirmation' => '123',
        ])->assertUnprocessable()->assertJsonValidationErrorFor('password');
    }

    public function test_login_com_credenciais_validas(): void
    {
        User::factory()->create([
            'email' => 'kaue@fiapx.test',
            'password' => Hash::make('senhaSegura1'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'kaue@fiapx.test',
            'password' => 'senhaSegura1',
        ])->assertOk()->assertJsonStructure(['token', 'expires_in']);
    }

    public function test_login_com_senha_errada(): void
    {
        User::factory()->create([
            'email' => 'kaue@fiapx.test',
            'password' => Hash::make('senhaSegura1'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'kaue@fiapx.test',
            'password' => 'senhaErrada1',
        ])->assertUnauthorized();
    }

    public function test_login_com_usuario_inexistente(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'ninguem@fiapx.test',
            'password' => 'qualquerSenha1',
        ])->assertUnauthorized();
    }

    public function test_rota_protegida_exige_token(): void
    {
        $this->getJson('/api/videos')->assertUnauthorized();
    }

    public function test_rota_protegida_recusa_token_invalido(): void
    {
        $this->withHeader('Authorization', 'Bearer token-forjado')
            ->getJson('/api/videos')
            ->assertUnauthorized();
    }

    public function test_rota_protegida_recusa_token_de_usuario_removido(): void
    {
        $user = User::factory()->create();
        $token = app(Jwt::class)->issue($user->id);
        $user->delete();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/videos')
            ->assertUnauthorized();
    }

    public function test_me_devolve_o_usuario_do_token(): void
    {
        $user = User::factory()->create();
        $token = app(Jwt::class)->issue($user->id);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_resposta_carrega_id_de_correlacao(): void
    {
        $this->getJson('/api/health')->assertOk()->assertHeader('X-Correlation-Id');
    }

    public function test_id_de_correlacao_enviado_pelo_cliente_e_preservado(): void
    {
        $this->withHeader('X-Correlation-Id', 'rastreio-123')
            ->getJson('/api/health')
            ->assertHeader('X-Correlation-Id', 'rastreio-123');
    }
}
