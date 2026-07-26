<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Video extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PROCESSING = 'PROCESSING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'user_id',
        'original_filename',
        'object_key',
        'zip_object_key',
        'status',
        'size_bytes',
        'zip_size_bytes',
        'frame_count',
        'attempts',
        'error_message',
        'correlation_id',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'zip_size_bytes' => 'integer',
            'frame_count' => 'integer',
            'attempts' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Restringe a consulta aos videos de um usuario.
     *
     * Toda leitura passa por aqui: e o que impede um usuario de enxergar
     * o video de outro apenas adivinhando o UUID.
     */
    public function scopeOfUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeWithStatus(Builder $query, ?string $status): Builder
    {
        return $status === null ? $query : $query->where('status', $status);
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_COMPLETED && filled($this->zip_object_key);
    }

    /**
     * Indica se o video ainda esta em andamento, o que evita reprocessamento
     * e habilita o polling no cliente.
     */
    public function isInProgress(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true);
    }
}
