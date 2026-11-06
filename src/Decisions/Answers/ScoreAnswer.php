<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Decisions\Answers;

use CPointLabs\HostYourAI\Support\Payload;

final readonly class ScoreAnswer extends Answer
{
    /**
     * @param array<array-key, mixed> $legend Each level's description, keyed by its score.
     * @param array<array-key, float> $probabilities Each level's probability, keyed by its score.
     */
    public function __construct(
        public float $score,
        public float $confidence,
        public array $legend,
        public array $probabilities,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            self::number($data, 'score'),
            self::number($data, 'confidence'),
            Payload::map($data, 'legend'),
            Payload::floats($data, 'probabilities'),
        );
    }
}
