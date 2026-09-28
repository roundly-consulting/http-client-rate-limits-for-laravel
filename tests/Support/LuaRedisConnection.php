<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Tests\Support;

use Closure;
use Illuminate\Redis\Connections\Connection;
use RuntimeException;

/**
 * A Redis connection for machines without a redis-server (the RedisStore cases run against a
 * real Redis on CI as well). Every command — plain ones and EVAL alike — is executed by a
 * real Lua interpreter against `redis-mock.lua`, a redis.call() implementing the commands
 * RedisStore uses with Redis's semantics (unique sorted-set members, exclusive/infinite
 * score bounds, LIMIT, GET of a missing key = false, integer replies). EVAL runs the store's
 * actual script text, so the Lua that ships is the Lua that is tested.
 *
 * Prefers `lua5.1` — the dialect Redis embeds — and falls back to any `lua` on PATH.
 */
final class LuaRedisConnection extends Connection
{
    /** @var array<string, array<string, string>> key => member => score */
    public array $sortedSets = [];

    /** @var array<string, string> */
    public array $strings = [];

    /** @var array<string, int> */
    public array $ttls = [];

    /** @var list<string> every command run, upper-cased, in order */
    public array $log = [];

    public static function binary(): ?string
    {
        $candidates = array_filter([getenv('LUA_BINARY') ?: null, 'lua5.1', 'lua-5.1', 'lua']);

        foreach ($candidates as $candidate) {
            $path = trim((string) shell_exec('command -v '.escapeshellarg($candidate).' 2>/dev/null'));

            if ($path !== '') {
                return $path;
            }
        }

        return null;
    }

    public function command($method, array $parameters = [])
    {
        $name = strtoupper($method);
        $this->log[] = $name;

        if ($name === 'EVAL') {
            $script = (string) array_shift($parameters);
            $count = (int) array_shift($parameters);

            return $this->run(
                $script,
                array_map(strval(...), array_slice($parameters, 0, $count)),
                array_map(strval(...), array_slice($parameters, $count)),
            );
        }

        $arguments = array_map(strval(...), $parameters);

        return $this->run(
            'return redis.call('.self::literal($name).', unpack(ARGV))',
            [],
            $arguments,
        );
    }

    public function createSubscription($channels, Closure $callback, $method = 'subscribe'): void
    {
        throw new RuntimeException('Subscriptions are not emulated.');
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $arguments
     */
    private function run(string $script, array $keys, array $arguments): mixed
    {
        $binary = self::binary() ?? throw new RuntimeException('No Lua interpreter on PATH.');

        $program = implode("\n", [
            // Lua 5.2+ dropped these; Redis's 5.1 has them.
            'unpack = unpack or table.unpack',
            'table.getn = table.getn or function (t) return #t end',
            'ZSETS = '.$this->sortedSetsLiteral(),
            'STRINGS = '.self::tableLiteral($this->strings),
            'TTLS = {}',
            'KEYS = '.self::listLiteral($keys),
            'ARGV = '.self::listLiteral($arguments),
            (string) file_get_contents(__DIR__.'/redis-mock.lua'),
            'local SCRIPT = function ()',
            $script,
            'end',
            'DUMP(SCRIPT())',
        ]);

        $file = tempnam(sys_get_temp_dir(), 'lua-redis-');
        file_put_contents($file, $program);

        exec(escapeshellarg($binary).' '.escapeshellarg($file).' 2>&1', $output, $status);
        unlink($file);

        if ($status !== 0) {
            throw new RuntimeException("Lua failed:\n".implode("\n", $output));
        }

        return $this->absorb($output);
    }

    /**
     * Read the dump back: the reply, then the full state after the script ran.
     *
     * @param  list<string>  $lines
     */
    private function absorb(array $lines): mixed
    {
        $ttls = $this->ttls;
        $this->sortedSets = [];
        $this->strings = [];
        $this->ttls = [];

        $reply = null;
        $pending = 0;
        $items = [];

        foreach ($lines as $line) {
            $fields = explode("\t", $line);

            if ($fields[0] === 'REPLY' && $fields[1] === 'A') {
                [$pending, $reply] = [(int) $fields[2], []];
            } elseif ($fields[0] === 'REPLY') {
                $reply = self::value($fields);
            } elseif ($fields[0] === 'ITEM') {
                $items[] = self::value($fields);
            } elseif ($fields[0] === 'Z') {
                $this->sortedSets[(string) hex2bin($fields[1])][(string) hex2bin($fields[2])] = $fields[3];
            } elseif ($fields[0] === 'S') {
                $this->strings[(string) hex2bin($fields[1])] = (string) hex2bin($fields[2]);
            } elseif ($fields[0] === 'T') {
                $this->ttls[(string) hex2bin($fields[1])] = (int) $fields[2];
            } else {
                throw new RuntimeException("Unexpected Lua output: {$line}");
            }
        }

        // Keys the script did not touch keep their TTL, as in Redis.
        foreach ($ttls as $key => $ttl) {
            if (! isset($this->ttls[$key]) && (isset($this->sortedSets[$key]) || isset($this->strings[$key]))) {
                $this->ttls[$key] = $ttl;
            }
        }

        return is_array($reply) ? array_slice($items, 0, $pending) : $reply;
    }

    /**
     * @param  list<string>  $fields
     */
    private static function value(array $fields): int|string|null
    {
        return match ($fields[1]) {
            'I' => (int) $fields[2],
            'S' => (string) hex2bin($fields[2]),
            default => null,
        };
    }

    private function sortedSetsLiteral(): string
    {
        $sets = [];

        foreach ($this->sortedSets as $key => $members) {
            $entries = [];

            foreach ($members as $member => $score) {
                $entries[] = '['.self::literal((string) $member).'] = '.self::number($score);
            }

            $sets[] = '['.self::literal((string) $key).'] = {'.implode(', ', $entries).'}';
        }

        return '{'.implode(', ', $sets).'}';
    }

    /**
     * @param  array<string, string>  $values
     */
    private static function tableLiteral(array $values): string
    {
        $entries = [];

        foreach ($values as $key => $value) {
            $entries[] = '['.self::literal((string) $key).'] = '.self::literal($value);
        }

        return '{'.implode(', ', $entries).'}';
    }

    /**
     * @param  list<string>  $values
     */
    private static function listLiteral(array $values): string
    {
        return '{'.implode(', ', array_map(self::literal(...), $values)).'}';
    }

    /**
     * A Lua string literal of any bytes: every byte as a decimal escape.
     */
    private static function literal(string $value): string
    {
        return '"'.implode('', array_map(
            static fn (string $byte): string => '\\'.ord($byte),
            $value === '' ? [] : str_split($value),
        )).'"';
    }

    private static function number(string $score): string
    {
        return is_numeric($score) ? 'tonumber('.self::literal($score).')' : 'nil';
    }
}
