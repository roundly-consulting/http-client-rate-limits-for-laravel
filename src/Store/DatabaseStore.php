<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Store;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\HttpClientRateLimits\Models\RateLimitHit;

/**
 * Shares rate-limit state through a database table, for apps that run only a
 * file/database cache and have no Redis. Backed by Eloquent (never the DB facade)
 * so it honours the host's configured connection.
 */
final class DatabaseStore implements Store
{
    public function hit(string $owner, int $timestamp): void
    {
        $this->query()->create([
            'owner' => $owner,
            'hit_at' => $timestamp,
        ]);
    }

    /**
     * @return list<int>
     */
    public function hits(string $owner): array
    {
        return $this->hitsSince($owner, 0);
    }

    /**
     * @return list<int>
     */
    public function hitsSince(string $owner, int $timestamp): array
    {
        $hits = $this->query()
            ->where('owner', $owner)
            ->whereNotNull('hit_at')
            ->where('hit_at', '>=', $timestamp)
            ->orderBy('hit_at')
            ->pluck('hit_at')
            ->all();

        $list = [];

        foreach ($hits as $hit) {
            $list[] = (int) $hit;
        }

        return $list;
    }

    public function clear(string $owner, int $timestamp): void
    {
        $this->query()
            ->where('owner', $owner)
            ->whereNotNull('hit_at')
            ->where('hit_at', '<=', $timestamp)
            ->forceDelete();
    }

    public function penalizeUntil(string $owner, int $timestamp): void
    {
        $row = $this->query()
            ->where('owner', $owner)
            ->whereNull('hit_at')
            ->whereNotNull('penalized_until')
            ->first();

        if ($row === null) {
            $this->query()->create([
                'owner' => $owner,
                'hit_at' => null,
                'penalized_until' => $timestamp,
            ]);

            return;
        }

        if ($row->penalized_until === null || $timestamp > $row->penalized_until) {
            $row->update(['penalized_until' => $timestamp]);
        }
    }

    public function penalizedUntil(string $owner): ?int
    {
        $row = $this->query()
            ->where('owner', $owner)
            ->whereNull('hit_at')
            ->whereNotNull('penalized_until')
            ->first();

        return $row?->penalized_until;
    }

    /**
     * @return Builder<RateLimitHit>
     */
    protected function query()
    {
        return RateLimitHit::query();
    }
}
