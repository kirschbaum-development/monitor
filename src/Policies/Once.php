<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Policies;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Kirschbaum\Monitor\Contracts\Policy;
use Kirschbaum\Monitor\Risks\Duplicate;
use Kirschbaum\Monitor\Run;
use Throwable;

/**
 * Run at most once per idempotency key inside a window.
 *
 * The key is claimed in the cache before anything executes; a second run with
 * the same key inside the window is refused with a Duplicate risk. A run whose
 * policies fail releases the key, so the operation can be tried again; a run
 * that completed keeps it, because the side effect has happened even if a
 * later ensure() rejects the result.
 */
class Once implements Policy
{
    final public function __construct(protected string $key, protected int $ttl = 3600) {}

    public static function key(string $key, int $ttl = 3600): static
    {
        return new static($key, $ttl);
    }

    public function around(Run $run, Closure $next): mixed
    {
        $cacheKey = $this->cacheKey($run->info()->point);
        $cache = Cache::store(self::store());

        if (! $cache->add($cacheKey, $run->info()->id, max(1, $this->ttl))) {
            $owner = $cache->get($cacheKey);
            $run->note('once.duplicate', ['key' => $this->key]);

            throw new Duplicate($this->key, is_string($owner) ? $owner : null);
        }

        try {
            return $next();
        } catch (Throwable $e) {
            $cache->forget($cacheKey);

            throw $e;
        }
    }

    public function order(): int
    {
        return Policy::ORDER_ONCE;
    }

    public function describe(): array
    {
        return ['type' => 'once', 'key' => $this->key, 'ttl' => $this->ttl];
    }

    protected function cacheKey(string $point): string
    {
        return Config::string('monitor.once.prefix', 'monitor:once:').$point.':'.$this->key;
    }

    protected static function store(): ?string
    {
        $store = Config::get('monitor.once.store');

        return is_string($store) && $store !== '' ? $store : null;
    }
}
