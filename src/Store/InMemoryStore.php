<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Store;

class InMemoryStore implements Store
{
    /** @var array<string, list<int>> */
    protected array $timestamps = [];

    public function hit(string $owner, int $timestamp): void
    {
        if (! array_key_exists($owner, $this->timestamps)) {
            $this->timestamps[$owner] = [];
        }

        $this->timestamps[$owner][] = $timestamp;
    }

    /**
     * @return list<int>
     */
    public function hits(string $owner): array
    {
        return $this->timestamps[$owner] ?? [];
    }

    /**
     * @return list<int>
     */
    public function hitsSince(string $owner, int $timestamp): array
    {
        return array_values(
            array_filter($this->hits($owner), fn (int $record) => $record >= $timestamp),
        );
    }

    public function clear(string $owner, int $timestamp): void
    {
        $this->timestamps[$owner] = array_values(
            array_filter($this->hits($owner), fn (int $record) => $record > $timestamp),
        );
    }
}
