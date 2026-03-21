<?php

namespace samuelreichor\coPilot\helpers;

use Craft;
use samuelreichor\coPilot\constants\Constants;
use yii\caching\TagDependency;

final class CacheHelper
{
    /**
     * Returns the cache duration for CoPilot caches.
     * Falls back to Craft's configured cacheDuration.
     */
    public static function getCacheDuration(): int
    {
        return Craft::$app->getConfig()->getGeneral()->cacheDuration;
    }

    /**
     * Stores a value in the CoPilot cache with tag dependency.
     *
     * @param mixed $value
     */
    public static function set(string $key, mixed $value): void
    {
        $dependency = new TagDependency(['tags' => [Constants::CACHE_TAG]]);
        Craft::$app->getCache()->set($key, $value, self::getCacheDuration(), $dependency);
    }

    /**
     * Retrieves a value from the CoPilot cache.
     */
    public static function get(string $key): mixed
    {
        return Craft::$app->getCache()->get($key);
    }

    /**
     * Invalidates all CoPilot caches.
     */
    public static function invalidateAll(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), Constants::CACHE_TAG);
    }
}
