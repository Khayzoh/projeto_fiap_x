<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_concluido_e_baixavel(): void
    {
        $video = Video::factory()->completed()->make();

        $this->assertTrue($video->isDownloadable());
        $this->assertFalse($video->isInProgress());
    }

    public function test_video_concluido_sem_zip_nao_e_baixavel(): void
    {
        // Estado inconsistente: marcado como concluido mas sem o objeto no storage.
        $video = Video::factory()->completed()->make(['zip_object_key' => null]);

        $this->assertFalse($video->isDownloadable());
    }

    public function test_video_pendente_esta_em_andamento(): void
    {
        $video = Video::factory()->make();

        $this->assertTrue($video->isInProgress());
        $this->assertFalse($video->isDownloadable());
    }

    public function test_video_com_falha_nao_esta_em_andamento(): void
    {
        $video = Video::factory()->failed()->make();

        $this->assertFalse($video->isInProgress());
        $this->assertFalse($video->isDownloadable());
    }

    public function test_scope_of_user_isola_os_videos_por_dono(): void
    {
        $dono = User::factory()->create();
        $outro = User::factory()->create();

        Video::factory()->count(3)->create(['user_id' => $dono->id]);
        Video::factory()->count(2)->create(['user_id' => $outro->id]);

        $this->assertCount(3, Video::query()->ofUser($dono->id)->get());
        $this->assertCount(2, Video::query()->ofUser($outro->id)->get());
    }

    public function test_scope_with_status_filtra_quando_informado(): void
    {
        $user = User::factory()->create();
        Video::factory()->count(2)->completed()->create(['user_id' => $user->id]);
        Video::factory()->failed()->create(['user_id' => $user->id]);

        $this->assertCount(2, Video::query()->withStatus(Video::STATUS_COMPLETED)->get());
        $this->assertCount(1, Video::query()->withStatus(Video::STATUS_FAILED)->get());
        // Sem filtro, devolve tudo.
        $this->assertCount(3, Video::query()->withStatus(null)->get());
    }

    public function test_id_e_uuid(): void
    {
        $video = Video::factory()->create();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $video->id
        );
    }
}
