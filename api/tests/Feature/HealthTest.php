<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_responde_sem_depender_de_infraestrutura(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'fiapx-api');
    }

    public function test_probes_sao_publicos(): void
    {
        // O kubelet e o Prometheus nao carregam token: estas rotas ficam fora do jwt.
        $this->getJson('/api/health')->assertOk();
        $this->get('/api/metrics')->assertOk();
    }

    public function test_metricas_saem_no_formato_do_prometheus(): void
    {
        $user = User::factory()->create();
        Video::factory()->count(2)->completed()->create(['user_id' => $user->id]);
        Video::factory()->failed()->create(['user_id' => $user->id]);

        $resposta = $this->get('/api/metrics');

        $resposta->assertOk()
            ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=UTF-8');

        $corpo = $resposta->getContent();

        $this->assertStringContainsString('# TYPE fiapx_videos_total gauge', $corpo);
        $this->assertStringContainsString('fiapx_videos_total{status="COMPLETED"} 2', $corpo);
        $this->assertStringContainsString('fiapx_videos_total{status="FAILED"} 1', $corpo);
        // Status sem nenhum video ainda precisa aparecer zerado, senao o
        // grafico do Grafana fica com buracos em vez de zero.
        $this->assertStringContainsString('fiapx_videos_total{status="PENDING"} 0', $corpo);
        $this->assertStringContainsString('fiapx_api_up 1', $corpo);
    }
}
