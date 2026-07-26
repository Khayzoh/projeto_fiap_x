<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Messaging\VideoEventPublisher;
use App\Models\User;
use App\Models\Video;
use App\Support\Jwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class VideoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create();
    }

    private function autenticado(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.app(Jwt::class)->issue($this->user->id));
    }

    /**
     * O broker nao participa dos testes: o que importa aqui e que a API
     * publica o evento exatamente uma vez por upload aceito.
     */
    private function esperarPublicacao(int $vezes = 1): void
    {
        $this->mock(VideoEventPublisher::class, function (MockInterface $mock) use ($vezes) {
            $mock->shouldReceive('publishUploaded')->times($vezes);
        });
    }

    // ---- upload ----

    public function test_upload_aceito_responde_202_e_registra_pendente(): void
    {
        $this->esperarPublicacao();

        $resposta = $this->autenticado()->postJson('/api/videos', [
            'video' => UploadedFile::fake()->create('ferias.mp4', 2048, 'video/mp4'),
        ]);

        $resposta->assertAccepted()
            ->assertJsonPath('data.status', Video::STATUS_PENDING)
            ->assertJsonPath('data.filename', 'ferias.mp4')
            ->assertJsonPath('data.in_progress', true)
            ->assertJsonPath('data.downloadable', false);

        $this->assertDatabaseHas('videos', [
            'user_id' => $this->user->id,
            'original_filename' => 'ferias.mp4',
            'status' => Video::STATUS_PENDING,
        ]);
    }

    public function test_upload_guarda_o_arquivo_no_storage(): void
    {
        $this->esperarPublicacao();

        $this->autenticado()->postJson('/api/videos', [
            'video' => UploadedFile::fake()->create('ferias.mp4', 1024, 'video/mp4'),
        ])->assertAccepted();

        $video = Video::query()->firstOrFail();
        Storage::disk('local')->assertExists($video->object_key);
    }

    public function test_upload_exige_autenticacao(): void
    {
        $this->postJson('/api/videos', [
            'video' => UploadedFile::fake()->create('ferias.mp4', 1024, 'video/mp4'),
        ])->assertUnauthorized();
    }

    public function test_upload_recusa_formato_nao_suportado(): void
    {
        $this->autenticado()->postJson('/api/videos', [
            'video' => UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrorFor('video');

        $this->assertDatabaseCount('videos', 0);
    }

    public function test_upload_recusa_arquivo_acima_do_limite(): void
    {
        $limiteKb = (int) config('fiapx.upload.max_size_kb');

        $this->autenticado()->postJson('/api/videos', [
            'video' => UploadedFile::fake()->create('gigante.mp4', $limiteKb + 1024, 'video/mp4'),
        ])->assertUnprocessable()->assertJsonValidationErrorFor('video');
    }

    public function test_upload_sem_arquivo(): void
    {
        $this->autenticado()->postJson('/api/videos', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('video');
    }

    // ---- listagem ----

    public function test_listagem_traz_apenas_os_videos_do_usuario(): void
    {
        Video::factory()->count(3)->create(['user_id' => $this->user->id]);
        Video::factory()->count(2)->create(); // de outro usuario

        $this->autenticado()->getJson('/api/videos')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_listagem_filtra_por_status(): void
    {
        Video::factory()->count(2)->completed()->create(['user_id' => $this->user->id]);
        Video::factory()->failed()->create(['user_id' => $this->user->id]);

        $this->autenticado()->getJson('/api/videos?status=COMPLETED')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', Video::STATUS_COMPLETED);
    }

    public function test_listagem_recusa_status_desconhecido(): void
    {
        $this->autenticado()->getJson('/api/videos?status=INVENTADO')
            ->assertUnprocessable();
    }

    public function test_listagem_vem_ordenada_do_mais_recente_para_o_mais_antigo(): void
    {
        $antigo = Video::factory()->create([
            'user_id' => $this->user->id,
            'created_at' => now()->subDays(2),
        ]);
        $recente = Video::factory()->create([
            'user_id' => $this->user->id,
            'created_at' => now(),
        ]);

        $this->autenticado()->getJson('/api/videos')
            ->assertOk()
            ->assertJsonPath('data.0.id', $recente->id)
            ->assertJsonPath('data.1.id', $antigo->id);
    }

    public function test_listagem_e_paginada(): void
    {
        Video::factory()->count(25)->create(['user_id' => $this->user->id]);

        $this->autenticado()->getJson('/api/videos?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 25);
    }

    // ---- detalhe ----

    public function test_detalhe_do_proprio_video(): void
    {
        $video = Video::factory()->completed()->create(['user_id' => $this->user->id]);

        $this->autenticado()->getJson("/api/videos/{$video->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $video->id)
            ->assertJsonPath('data.downloadable', true);
    }

    public function test_video_de_outro_usuario_responde_404(): void
    {
        $alheio = Video::factory()->completed()->create();

        // 404 e nao 403: nao revela sequer que o recurso existe.
        $this->autenticado()->getJson("/api/videos/{$alheio->id}")->assertNotFound();
    }

    // ---- download ----

    public function test_download_de_video_ainda_em_processamento_responde_409(): void
    {
        $video = Video::factory()->processing()->create(['user_id' => $this->user->id]);

        $this->autenticado()->getJson("/api/videos/{$video->id}/download")
            ->assertStatus(409)
            ->assertJsonPath('status', Video::STATUS_PROCESSING);
    }

    public function test_download_de_video_com_falha_responde_409(): void
    {
        $video = Video::factory()->failed()->create(['user_id' => $this->user->id]);

        $this->autenticado()->getJson("/api/videos/{$video->id}/download")
            ->assertStatus(409);
    }

    public function test_download_de_video_alheio_responde_404(): void
    {
        $alheio = Video::factory()->completed()->create();

        $this->autenticado()->getJson("/api/videos/{$alheio->id}/download")->assertNotFound();
    }

    public function test_download_exige_autenticacao(): void
    {
        $video = Video::factory()->completed()->create(['user_id' => $this->user->id]);

        $this->getJson("/api/videos/{$video->id}/download")->assertUnauthorized();
    }
}
