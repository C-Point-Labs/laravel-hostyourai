<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Support;

/**
 * Reads decoded JSON and config values by their actual type. A value of another
 * type counts as missing, so odd API data never turns into a wrong cast.
 *
 * @internal
 */
final class Payload
{
    /**
     * @param array<array-key, mixed> $data
     */
    public static function string(array $data, string $path): ?string
    {
        $value = data_get($data, $path);

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function int(array $data, string $path): ?int
    {
        $value = data_get($data, $path);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function float(array $data, string $path): ?float
    {
        $value = data_get($data, $path);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function bool(array $data, string $path): ?bool
    {
        $value = data_get($data, $path);

        return is_bool($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    public static function map(array $data, string $path): array
    {
        $value = data_get($data, $path);

        return is_array($value) ? self::stringKeys($value) : [];
    }

    /**
     * The arrays found at the path. Anything else in there is skipped.
     *
     * @param array<array-key, mixed> $data
     * @return list<array<string, mixed>>
     */
    public static function maps(array $data, string $path): array
    {
        $value = data_get($data, $path);
        $maps = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_array($item)) {
                $maps[] = self::stringKeys($item);
            }
        }

        return $maps;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, float>
     */
    public static function floats(array $data, string $path): array
    {
        $value = data_get($data, $path);
        $floats = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_numeric($item)) {
                $floats[$key] = (float) $item;
            }
        }

        return $floats;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<string>
     */
    public static function strings(array $data, string $path): array
    {
        $value = data_get($data, $path);
        $strings = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * Narrows the keys for static analysis. It changes nothing at runtime: PHP keeps
     * a numeric key as an int, so don't rely on these keys being strings.
     *
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    public static function stringKeys(array $data): array
    {
        $mapped = [];

        foreach ($data as $key => $value) {
            $mapped[(string) $key] = $value;
        }

        return $mapped;
    }
}
