<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\DataTransferObjects;

use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;
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
     * Build from a raw config array, defaulting any omitted keys.
     *
     * `trim` and `adaptive` are read strictly: `(bool) 'off'` is true, so an env
     * `off`/`no` used to switch them ON. Anything but a boolean spelling throws
     * InvalidConfigurationException naming `$key.trim` / `$key.adaptive`.
     *
     * @param  array<string, mixed>  $config
     * @param  string|null  $key  the profile's config key, named in errors
     */
    public static function fromConfig(array $config, ?string $key = null): self
    {
        $flag = static function (string $leaf) use ($config, $key): bool {
            $name = $key === null ? $leaf : "{$key}.{$leaf}";

            return Config::for([$name => $config[$leaf] ?? null])->boolean($name, false);
        };

        $rate = $config['rate'] ?? 1;
        $per = $config['per'] ?? Timespan::Minute->value;
        $by = $config['by'] ?? null;
        $maxWait = $config['max_wait'] ?? null;
        $jitter = $config['jitter'] ?? 0;

        return new self(
            rate: is_numeric($rate) ? (int) $rate : 1,
            per: $per instanceof Timespan ? $per : Timespan::fromValue((string) $per),
            by: is_string($by) ? $by : null,
            trim: $flag('trim'),
            maxWaitMs: is_numeric($maxWait) ? (int) $maxWait : null,
            jitterMs: is_numeric($jitter) ? (int) $jitter : 0,
            adaptive: $flag('adaptive'),
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
