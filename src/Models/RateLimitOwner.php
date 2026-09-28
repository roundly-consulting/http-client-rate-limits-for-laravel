<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\HttpClientRateLimits\Database\Factories\RateLimitOwnerFactory;

/**
 * One limit key in the DatabaseStore: the row an attempt locks, and the adaptive penalty.
 *
 * @property int $id
 * @property string $owner
 * @property int|null $penalized_until
 * @property int $touched_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
final class RateLimitOwner extends Model
{
    /** @use HasFactory<RateLimitOwnerFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'http_client_rate_limit_owners';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'penalized_until' => 'integer',
            'touched_at' => 'integer',
        ];
    }

    protected static function newFactory(): RateLimitOwnerFactory
    {
        return RateLimitOwnerFactory::new();
    }
}
