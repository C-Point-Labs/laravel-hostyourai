<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Decisions\Questions;

use InvalidArgumentException;

/**
 * Rates against ordered levels. A level's position is its score, starting at 0.
 */
final readonly class Score implements Question
{
    public const MIN_LEVELS = 1;

    public const MAX_LEVELS = 10;

    /** @var list<mixed> */
    public array $levels;

    /**
     * @param string|array<mixed> $instructions
     * @param array<int, mixed> $levels
     */
    public function __construct(public string|array $instructions, array $levels)
    {
        $count = count($levels);

        if ($count < self::MIN_LEVELS || $count > self::MAX_LEVELS) {
            throw new InvalidArgumentException(sprintf(
                'A score question needs %d to %d levels, %d given.',
                self::MIN_LEVELS,
                self::MAX_LEVELS,
                $count,
            ));
        }

        $this->levels = array_values($levels);
    }

    /**
     * @param string|array<mixed> $instructions
     * @param array<int, mixed> $levels
     */
    public static function make(string|array $instructions, array $levels): self
    {
        return new self($instructions, $levels);
    }

    public function toArray(): array
    {
        return ['type' => 'score', 'instructions' => $this->instructions, 'criteria' => $this->levels];
    }
}
