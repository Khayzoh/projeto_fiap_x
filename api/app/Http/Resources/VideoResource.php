<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Video
 */
class VideoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->original_filename,
            'status' => $this->status,
            'size_bytes' => $this->size_bytes,
            'frame_count' => $this->frame_count,
            'zip_size_bytes' => $this->zip_size_bytes,
            'attempts' => $this->attempts,
            'error_message' => $this->error_message,
            'in_progress' => $this->isInProgress(),
            'downloadable' => $this->isDownloadable(),
            // Link so aparece quando ha o que baixar, evitando que o cliente
            // precise adivinhar a rota a partir do status.
            'download_url' => $this->when(
                $this->isDownloadable(),
                fn () => route('videos.download', ['id' => $this->id]),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
        ];
    }
}
