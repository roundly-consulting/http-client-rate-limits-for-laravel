<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\HttpClientRateLimits\Database\Factories\RateLimitHitFactory;

/**
 * @property int $id
 * @property string $owner
 * @property int|null $hit_at
 * @property int|null $penalized_until
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
final class RateLimitHit extends Model
{
    /** @use HasFactory<RateLimitHitFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'http_client_rate_limits';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hit_at' => 'integer',
            'penalized_until' => 'integer',
        ];
    }

    protected static function newFactory(): RateLimitHitFactory
    {
        return RateLimitHitFactory::new();
    }
}
