<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mail\VideoProcessedMail;
use App\Mail\VideoProcessingFailedMail;
use App\Models\User;
use App\Models\Video;
use App\Services\VideoStatusUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VideoStatusUpdaterTest extends TestCase
{
    use RefreshDatabase;

    private VideoStatusUpdater $updater;

    protected function setUp(): void
    {
        parent::setUp();
        $this->updater = new VideoStatusUpdater;
        Mail::fake();
    }

    public function test_marca_video_como_concluido(): void
    {
        $video = Video::factory()->processing()->create();

        $tratado = $this->updater->markCompleted([
            'video_id' => $video->id,
            'zip_object_key' => "outputs/{$video->id}/frames.zip",
            'frame_count' => 120,
            'zip_size_bytes' => 4_500_000,
            'processed_at' => '2026-07-26T12:00:00Z',
        ]);

        $this->assertTrue($tratado);

        $video->refresh();
        $this->assertSame(Video::STATUS_COMPLETED, $video->status);
        $this->assertSame(120, $video->frame_count);
        $this->assertSame(4_500_000, $video->zip_size_bytes);
        $this->assertTrue($video->isDownloadable());
        $this->assertNull($video->error_message);
    }

    public function test_marca_video_como_falho_e_notifica_o_dono(): void
    {
        $user = User::factory()->create(['email' => 'dono@fiapx.test']);
        $video = Video::factory()->processing()->create(['user_id' => $user->id]);

        $tratado = $this->updater->markFailed([
            'video_id' => $video->id,
            'reason' => 'ffmpeg exit 1',
            'attempts' => 3,
            'failed_at' => '2026-07-26T12:05:00Z',
        ]);

        $this->assertTrue($tratado);

        $video->refresh();
        $this->assertSame(Video::STATUS_FAILED, $video->status);
        $this->assertSame('ffmpeg exit 1', $video->error_message);
        $this->assertSame(3, $video->attempts);

        // O requisito e explicito: em caso de erro o usuario deve ser notificado.
        Mail::assertSent(VideoProcessingFailedMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_conclusao_notifica_o_dono(): void
    {
        $user = User::factory()->create(['email' => 'dono@fiapx.test']);
        $video = Video::factory()->processing()->create(['user_id' => $user->id]);

        $this->updater->markCompleted([
            'video_id' => $video->id,
            'zip_object_key' => "outputs/{$video->id}/frames.zip",
            'frame_count' => 42,
        ]);

        Mail::assertSent(VideoProcessedMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
        Mail::assertNotSent(VideoProcessingFailedMail::class);
    }

    public function test_reentrega_de_conclusao_nao_duplica_efeito(): void
    {
        $video = Video::factory()->completed()->create(['frame_count' => 99]);

        $this->updater->markCompleted([
            'video_id' => $video->id,
            'frame_count' => 1,
        ]);

        $video->refresh();
        // O estado final ja estava gravado: a reentrega nao pode sobrescrever.
        $this->assertSame(99, $video->frame_count);
        // Nem reenviar o aviso de conclusao.
        Mail::assertNothingSent();
    }

    public function test_reentrega_de_falha_nao_reenvia_email(): void
    {
        $video = Video::factory()->failed()->create();

        $this->updater->markFailed([
            'video_id' => $video->id,
            'reason' => 'outra razao',
        ]);

        Mail::assertNothingSent();
    }

    public function test_evento_de_video_inexistente_e_ignorado(): void
    {
        $this->assertFalse($this->updater->markCompleted(['video_id' => '00000000-0000-4000-8000-000000000000']));
        $this->assertFalse($this->updater->markFailed(['video_id' => '00000000-0000-4000-8000-000000000000']));
    }

    public function test_evento_sem_identificador_e_ignorado(): void
    {
        $this->assertFalse($this->updater->markCompleted([]));
        $this->assertFalse($this->updater->markFailed([]));
    }

    public function test_falha_sem_motivo_usa_mensagem_padrao(): void
    {
        $video = Video::factory()->processing()->create();

        $this->updater->markFailed(['video_id' => $video->id]);

        $video->refresh();
        $this->assertNotEmpty($video->error_message);
    }

    public function test_data_invalida_no_evento_cai_para_o_horario_atual(): void
    {
        $video = Video::factory()->processing()->create();

        $this->updater->markCompleted([
            'video_id' => $video->id,
            'processed_at' => 'data-que-nao-existe',
        ]);

        $video->refresh();
        $this->assertNotNull($video->processed_at);
    }
}
