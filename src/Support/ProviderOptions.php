<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Support;

use CPointLabs\HostYourAI\Exceptions\InvalidRequestException;

/**
 * Checks provider options against what each kind of request supports, so that
 * none of them is ever ignored without a word.
 *
 * @internal
 */
final class ProviderOptions
{
    public const TEXT = ['hyai.task', 'hyai.policy'];

    public const STRUCTURED = ['schema.strict', 'hyai.task', 'hyai.policy'];

    public const STREAM = ['reasoning', 'hyai.task', 'hyai.policy'];

    private const VALUES = [
        'reasoning' => [true, false],
        'schema.strict' => [true, false],
        'hyai.task' => ['chat', 'code', 'summarize', 'classify', 'extract', 'rag', 'legal', 'reason'],
        'hyai.policy' => ['balanced', 'speed', 'cost', 'quality', 'sovereign'],
    ];

    /**
     * @param mixed $options What the request holds
     * @param list<string> $allowed Dotted paths that this kind of request supports
     */
    public static function assertSupported(mixed $options, array $allowed): void
    {
        foreach (self::flatten(is_array($options) ? $options : []) as $path => $value) {
            if (! in_array($path, $allowed, true)) {
                throw new InvalidRequestException("The provider option \"{$path}\" is not supported. Supported here: ".implode(', ', $allowed).'.');
            }

            $values = self::VALUES[$path] ?? [];

            if (! in_array($value, $values, true)) {
                $expected = is_bool($values[0] ?? null) ? 'true or false' : 'one of '.implode(', ', array_filter($values, is_string(...)));

                throw new InvalidRequestException("The provider option \"{$path}\" must be {$expected}.");
            }
        }
    }

    /**
     * The part of the request body that comes from the options.
     *
     * @param mixed $options What the request holds, already checked
     * @return array<string, mixed>
     */
    public static function body(mixed $options): array
    {
        $hyai = is_array($options) ? ($options['hyai'] ?? null) : null;

        return is_array($hyai) && $hyai !== [] ? ['hyai' => $hyai] : [];
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
