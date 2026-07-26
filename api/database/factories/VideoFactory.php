<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
{
    protected $model = Video::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = (string) Str::uuid();

        return [
            'id' => $id,
            'user_id' => User::factory(),
            'original_filename' => fake()->word().'.mp4',
            'object_key' => "videos/{$id}/original.mp4",
            'status' => Video::STATUS_PENDING,
            'size_bytes' => fake()->numberBetween(1_000_000, 90_000_000),
            'attempts' => 0,
            'correlation_id' => (string) Str::uuid(),
        ];
    }

    public function processing(): static
    {
        return $this->state(fn () => ['status' => Video::STATUS_PROCESSING]);
    }

    public function completed(): static
    {
        return $this->state(function (array $attributes) {
            $id = $attributes['id'];

            return [
                'status' => Video::STATUS_COMPLETED,
                'zip_object_key' => "outputs/{$id}/frames.zip",
                'frame_count' => fake()->numberBetween(10, 500),
                'zip_size_bytes' => fake()->numberBetween(100_000, 20_000_000),
                'processed_at' => now(),
            ];
        });
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => Video::STATUS_FAILED,
            'error_message' => 'falha ao extrair frames do video',
            'attempts' => 3,
            'processed_at' => now(),
        ]);
    }
}
