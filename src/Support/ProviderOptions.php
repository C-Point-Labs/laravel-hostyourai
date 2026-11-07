<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Support;

use CPointLabs\HostYourAI\Exceptions\InvalidRequestException;

/**
 * Refuses provider options that would otherwise have no effect. HostYourAI
 * documents no extra chat request fields, so nothing here is passed through.
 *
 * @internal
 */
final class ProviderOptions
{
    /**
     * @param mixed $options What the request holds
     * @param list<string> $allowed Dotted paths that this kind of request supports, all of them booleans
     */
    public static function assertSupported(mixed $options, array $allowed): void
    {
        foreach (self::flatten(is_array($options) ? $options : []) as $path => $value) {
            if (! in_array($path, $allowed, true)) {
                $supported = $allowed === [] ? 'This kind of request supports none.' : 'Supported here: '.implode(', ', $allowed).'.';

                throw new InvalidRequestException("The provider option \"{$path}\" is not supported. {$supported}");
            }

            if (! is_bool($value)) {
                throw new InvalidRequestException("The provider option \"{$path}\" must be true or false.");
            }
        }
    }

    /**
     * @param array<mixed> $options
     * @return array<string, mixed>
     */
    private static function flatten(array $options, string $prefix = ''): array
    {
        $flat = [];

        foreach ($options as $key => $value) {
            $path = $prefix.$key;

            if (is_array($value) && $value !== []) {
                $flat = [...$flat, ...self::flatten($value, $path.'.')];
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }
}
