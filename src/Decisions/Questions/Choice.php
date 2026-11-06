<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Decisions\Questions;

use CPointLabs\HostYourAI\Support\Payload;
use InvalidArgumentException;

/**
 * Picks one option from the criteria you define.
 */
final readonly class Choice implements Question
{
    public const MIN_CRITERIA = 2;

    public const MAX_CRITERIA = 16;

    /** @var array<string, mixed> */
    public array $criteria;

    /**
     * @param string|array<mixed> $instructions
     * @param array<int|string, mixed> $criteria Names mapped to descriptions, or a list of names.
     */
    public function __construct(public string|array $instructions, array $criteria)
    {
        $count = count($criteria);

        if ($count < self::MIN_CRITERIA || $count > self::MAX_CRITERIA) {
            throw new InvalidArgumentException(sprintf(
                'A choice question needs %d to %d criteria, %d given.',
                self::MIN_CRITERIA,
                self::MAX_CRITERIA,
                $count,
            ));
        }

        $this->criteria = self::normalize($criteria);
    }

    /**
     * @param string|array<mixed> $instructions
     * @param array<int|string, mixed> $criteria
     */
    public static function make(string|array $instructions, array $criteria): self
    {
        return new self($instructions, $criteria);
    }

    public function toArray(): array
    {
        return ['type' => 'choice', 'instructions' => $this->instructions, 'criteria' => $this->criteria];
    }

    /**
     * @param array<int|string, mixed> $criteria
     * @return array<string, mixed>
     */
    private static function normalize(array $criteria): array
    {
        if (! array_is_list($criteria)) {
            return Payload::stringKeys($criteria);
        }

        $named = [];

        foreach ($criteria as $name) {
            // PHP would turn a name like "0" into a list index, and JSON would lose it.
            if (! is_string($name) || is_numeric($name)) {
                throw new InvalidArgumentException('A list of criteria must only hold names that are not numbers.');
            }

            $named[$name] = null;
        }

        return $named;
    }
}
