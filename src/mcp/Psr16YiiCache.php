<?php

namespace markhuot\craftai\mcp;

use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use yii\caching\CacheInterface as YiiCacheInterface;

/**
 * Wraps a Yii cache component as a PSR-16 SimpleCache so it can back the
 * MCP SDK's Psr16SessionStore. Whatever Craft is configured to use for
 * Craft::$app->getCache() (file, Redis, Memcached, ...) becomes the MCP
 * session backend.
 */
class Psr16YiiCache implements CacheInterface
{
    public function __construct(
        private readonly YiiCacheInterface $cache,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->cache->get($key);

        return $value === false ? $default : $value;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        return $this->cache->set($key, $value, $this->normalizeTtl($ttl));
    }

    public function delete(string $key): bool
    {
        return $this->cache->delete($key);
    }

    public function clear(): bool
    {
        return $this->cache->flush();
    }

    /**
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }

        return $out;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set($key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->delete($key) && $ok;
        }

        return $ok;
    }

    public function has(string $key): bool
    {
        return $this->cache->exists($key);
    }

    private function normalizeTtl(null|int|DateInterval $ttl): int
    {
        if ($ttl === null) {
            return 0;
        }
        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable('@0');

            return (int) ($now->add($ttl)->getTimestamp());
        }

        return $ttl;
    }
}
