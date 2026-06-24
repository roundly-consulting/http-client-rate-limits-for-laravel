<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\DataTransferObjects;

use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;
use RoundlyConsulting\HttpClientRateLimits\Limit;

/**
 * A named limiter profile resolved from config, turned into a Limit on demand.
 */
final readonly class LimiterProfileData
{
    public function __construct(
        public int $rate,
        public Timespan $per,
        public ?string $by = null,
        public bool $trim = false,
        public ?int $maxWaitMs = null,
        public int $jitterMs = 0,
        public bool $adaptive = false,
    ) {}

    /**
     * Build from a raw config array, defaulting any omitted keys.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $rate = $config['rate'] ?? 1;
        $per = $config['per'] ?? Timespan::Minute->value;
        $by = $config['by'] ?? null;
        $trim = $config['trim'] ?? false;
        $maxWait = $config['max_wait'] ?? null;
        $jitter = $config['jitter'] ?? 0;
        $adaptive = $config['adaptive'] ?? false;

        return new self(
            rate: is_numeric($rate) ? (int) $rate : 1,
            per: $per instanceof Timespan ? $per : Timespan::fromValue((string) $per),
            by: is_string($by) ? $by : null,
            trim: (bool) $trim,
            maxWaitMs: is_numeric($maxWait) ? (int) $maxWait : null,
            jitterMs: is_numeric($jitter) ? (int) $jitter : 0,
            adaptive: (bool) $adaptive,
        );
    }

    public function toLimit(): Limit
    {
        $limit = new Limit(
            key: $this->by ?? 'global',
            maxAttempts: $this->rate,
            timespan: $this->per,
            trim: $this->trim,
        );

        if ($this->maxWaitMs !== null) {
            $limit->maxWait($this->maxWaitMs);
        }

        if ($this->jitterMs > 0) {
            $limit->jitter($this->jitterMs);
        }

        if ($this->adaptive) {
            $limit->adaptive();
        }

        return $limit;
    }
}
