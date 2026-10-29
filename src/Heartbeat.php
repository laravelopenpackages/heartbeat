<?php

namespace LaravelOpenPackages\Heartbeat;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;

/**
 * Remembers WHEN each topic last changed, and answers "which of these changed since X?".
 *
 * A topic is any name your pages care about: "counts", "invoices", "reminders"…
 * Mark it changed with touch() — for everyone, or for one user — and every open page
 * listening for it learns on its next beat. Topics that change with time rather than
 * with a write ("a reminder became due") register a watcher() instead.
 *
 * Marks are millisecond timestamps in a cache store (no database, no read-modify-write),
 * compared with the `now` the client got from its previous beat — so client and server
 * clocks never need to agree.
 */
final class Heartbeat
{
    /** Topic names: letters, digits and - _ . : (e.g. "counts", "invoice:42"). */
    public const TOPIC_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_\-.:]{0,99}$/';

    /** @var array<string, Closure(?Authenticatable, CarbonImmutable, CarbonImmutable): bool> */
    private array $watchers = [];

    /**
     * @param  array{cache?: array{store?: string|null, prefix?: string, ttl?: int}}  $config
     */
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly array $config = [],
    ) {}

    /**
     * Mark topics as changed now — for everyone, or only for the given user.
     *
     * @param  string|list<string>  $topics
     */
    public function touch(string|array $topics, Authenticatable|int|string|null $for = null): void
    {
        $now = $this->now();
        $marks = [];
        foreach ((array) $topics as $topic) {
            $marks[$this->key($this->topic($topic), $for)] = $now;
        }

        if ($marks !== []) {
            $this->store()->putMany($marks, $this->ttl());
        }
    }

    /**
     * A topic that changes with time instead of with a write ("a reminder became due"):
     * the check runs on each beat that asks for the topic, unless a touch already
     * answered it. Register watchers once — in a service provider's boot() — never per
     * request (under Octane they'd pile up).
     *
     * @param  Closure(?Authenticatable $user, CarbonImmutable $since, CarbonImmutable $now): bool  $changedSince
     */
    public function watch(string $topic, Closure $changedSince): void
    {
        $this->watchers[$this->topic($topic)] = $changedSince;
    }

    /**
     * Of the given topics, those that changed at or after $since (ms) — for this user
     * (their own marks, the global ones and any watcher). In the order asked.
     *
     * @param  list<string>  $topics
     * @return list<string>
     */
    public function changedSince(int $since, array $topics, ?Authenticatable $user = null, ?int $now = null): array
    {
        $topics = array_values(array_unique(array_map(fn (string $t) => $this->topic($t), $topics)));
        if ($topics === []) {
            return [];
        }

        $keys = [];
        foreach ($topics as $topic) {
            $keys[] = $this->key($topic);
            if ($user !== null) {
                $keys[] = $this->key($topic, $user);
            }
        }
        $marks = $this->store()->many($keys);

        $changed = [];
        $sinceAt = null;
        $nowAt = null;
        foreach ($topics as $topic) {
            $last = max((int) ($marks[$this->key($topic)] ?? 0), $user !== null ? (int) ($marks[$this->key($topic, $user)] ?? 0) : 0);

            // ">=": a touch in the same millisecond as the previous answer still counts
            // (at worst one extra refresh — never a missed one).
            if ($last > 0 && $last >= $since) {
                $changed[] = $topic;

                continue;
            }

            if (isset($this->watchers[$topic])) {
                $sinceAt ??= CarbonImmutable::createFromTimestampMs($since);
                $nowAt ??= CarbonImmutable::createFromTimestampMs($now ?? $this->now());
                if (($this->watchers[$topic])($user, $sinceAt, $nowAt)) {
                    $changed[] = $topic;
                }
            }
        }

        return $changed;
    }

    /** The current time in milliseconds — what a beat answers as `now`. */
    public function now(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public function interval(): int
    {
        return max(1, (int) ($this->config['interval'] ?? 15));
    }

    /** @return list<string> topics with a watcher */
    public function watched(): array
    {
        return array_keys($this->watchers);
    }

    private function topic(string $topic): string
    {
        if (preg_match(self::TOPIC_PATTERN, $topic) !== 1) {
            throw new InvalidArgumentException("Invalid heartbeat topic [{$topic}]: use letters, digits and - _ . : (up to 100).");
        }

        return $topic;
    }

    private function key(string $topic, Authenticatable|int|string|null $for = null): string
    {
        $prefix = $this->config['cache']['prefix'] ?? 'heartbeat';
        $id = $for instanceof Authenticatable ? $for->getAuthIdentifier() : $for;

        return $id === null ? "{$prefix}:{$topic}" : "{$prefix}:{$topic}:user:{$id}";
    }

    private function store(): Repository
    {
        return $this->cache->store($this->config['cache']['store'] ?? null);
    }

    private function ttl(): int
    {
        return (int) ($this->config['cache']['ttl'] ?? 60 * 60 * 24 * 7);
    }
}
