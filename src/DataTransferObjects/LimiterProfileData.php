<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\DataTransferObjects;

use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidTimespanException;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\PackageToolkit\Support\Config;

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
     * Build from a raw config array, defaulting any omitted (or null) keys.
     *
     * Every present value is read strictly and throws naming its full key
     * (`$key.rate`, `$key.per`, …) rather than being guessed at:
     *
     *  - `rate`, `max_wait` and `jitter` must be an int or a canonical integer
     *    string (`'5'`, never `'five'` / `'5.5'` / `''`); `max_wait` and `jitter`
     *    must be at least 0, and a `rate` below 1 throws InvalidLimitException.
     *  - `per` must be a Timespan or one of its exact values; a typo throws
     *    InvalidTimespanException instead of quietly becoming a minute.
     *  - `by` must be a non-empty string.
     *  - `trim` and `adaptive` are booleans: `(bool) 'off'` is true, so an env
     *    `off`/`no` used to switch them ON.
     *
     * The rest throw InvalidConfigurationException.
     *
     * @param  array<string, mixed>  $config
     * @param  string|null  $key  the profile's config key, named in errors
     */
    public static function fromConfig(array $config, ?string $key = null): self
    {
        $name = static fn (string $leaf): string => $key === null ? $leaf : "{$key}.{$leaf}";

        // Re-key the profile under its full config path so every error names it.
        $values = [];

        foreach (['rate', 'per', 'by', 'trim', 'max_wait', 'jitter', 'adaptive'] as $leaf) {
            $values[$name($leaf)] = $config[$leaf] ?? null;
        }

        $read = Config::for($values);

        return new self(
            rate: $read->integer($name('rate'), 1),
            per: Config::for($values, InvalidTimespanException::class)->enum($name('per'), Timespan::class, Timespan::Minute),
            by: $values[$name('by')] === null ? null : $read->requireString($name('by')),
            trim: $read->boolean($name('trim'), false),
            maxWaitMs: $values[$name('max_wait')] === null ? null : $read->integer($name('max_wait'), 0, min: 0),
            jitterMs: $read->integer($name('jitter'), 0, min: 0),
            adaptive: $read->boolean($name('adaptive'), false),
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
