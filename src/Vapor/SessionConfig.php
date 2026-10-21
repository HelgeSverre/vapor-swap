<?php

declare(strict_types=1);

namespace VaporSwap\Vapor;

use VaporSwap\JsonFile;

/** Mirrors Vapor 1.x Config's public API, redirecting only its config file. */
final class SessionConfig
{
    public static function get($key, $default = null)
    {
        $value = self::load();
        if (null === $key) {
            return $value;
        }
        if (array_key_exists($key, $value)) {
            return $value[$key];
        }
        foreach (explode('.', (string) $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function set($key, $value)
    {
        (new JsonFile(self::path()))->update(static function (array $config) use ($key, $value): array {
            if (null === $key) {
                if (!is_array($value)) {
                    throw new \RuntimeException('Vapor configuration must be an array.');
                }

                return $value;
            }
            $target = &$config;
            $segments = explode('.', (string) $key);
            $last = array_pop($segments);
            foreach ($segments as $segment) {
                if (!isset($target[$segment]) || !is_array($target[$segment])) {
                    $target[$segment] = [];
                }
                $target = &$target[$segment];
            }
            $target[$last] = $value;

            return $config;
        });
    }

    public static function load()
    {
        return (new JsonFile(self::path()))->read();
    }

    private static function path()
    {
        $path = getenv('VAPOR_SWAP_SESSION');
        if (!$path || !is_file($path)) {
            throw new \RuntimeException('Session config is missing. Run vapor-swap swap <name> again or vapor-swap reset.');
        }

        return $path;
    }
}
