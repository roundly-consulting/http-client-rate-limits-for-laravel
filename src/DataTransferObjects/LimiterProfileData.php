<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\DataTransferObjects;

use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidTimespanException;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\Support\ConfigValue;
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
        public ?string $name = null,
    ) {}

    /**
     * Build from a raw config array, defaulting any key that is not set: omitted,
     * null or blank (a host's `KEY=`, empty or whitespace only).
     *
     * Every set value is read strictly and throws naming its full key
     * (`$key.rate`, `$key.per`, …) rather than being guessed at:
     *
     *  - `rate`, `max_wait` and `jitter` must be an int or a canonical integer
     *    string (`'5'`, never `'five'` / `'5.5'`); `max_wait` and `jitter`
     *    must be at least 0, and a `rate` below 1 throws InvalidLimitException.
     *  - `per` must be a Timespan or one of its exact values; a typo throws
     *    InvalidTimespanException instead of quietly becoming a minute.
     *  - `by` must be a string. Without one the limit is keyed by the profile `$name`, so
     *    every profile counts on its own budget (and takes its own server penalties).
     *  - `trim` and `adaptive` are booleans: `(bool) 'off'` is true, so an env
     *    `off`/`no` used to switch them ON.
     *
     * The rest throw InvalidConfigurationException.
     *
     * @param  array<string, mixed>  $config
     * @param  string|null  $key  the profile's config key, named in errors
     * @param  string|null  $name  the profile's name, its limit key when `by` is not set
     */
    public static function fromConfig(array $config, ?string $key = null, ?string $name = null): self
    {
        $path = static fn (string $leaf): string => $key === null ? $leaf : "{$key}.{$leaf}";

        // Re-key the profile under its full config path so every error names it.
        $values = [];

        foreach (['rate', 'per', 'by', 'trim', 'max_wait', 'jitter', 'adaptive'] as $leaf) {
            $values[$path($leaf)] = $config[$leaf] ?? null;
        }

        $read = Config::for($values);

        return new self(
            rate: $read->integer($path('rate'), 1),
            per: Config::for($values, InvalidTimespanException::class)->enum($path('per'), Timespan::class, Timespan::Minute),
            by: ConfigValue::isSet($values[$path('by')]) ? $read->requireString($path('by')) : null,
            trim: $read->boolean($path('trim'), false),
            maxWaitMs: ConfigValue::isSet($values[$path('max_wait')]) ? $read->integer($path('max_wait'), 0, min: 0) : null,
            jitterMs: $read->integer($path('jitter'), 0, min: 0),
            adaptive: $read->boolean($path('adaptive'), false),
            name: $name,
        );
    }

    /**
     * The profile as a Limit keyed by `by`, else by the profile's name — never a bucket shared
     * with other profiles. Only a profile built without a name falls back to `global`.
     */
    public function toLimit(): Limit
    {
        $limit = new Limit(
            key: $this->by ?? $this->name ?? 'global',
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
