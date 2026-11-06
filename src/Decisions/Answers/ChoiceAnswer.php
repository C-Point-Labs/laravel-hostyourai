<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Decisions\Answers;

use CPointLabs\HostYourAI\Exceptions\UnexpectedResponseException;
use CPointLabs\HostYourAI\Support\Payload;

final readonly class ChoiceAnswer extends Answer
{
    /**
     * @param array<array-key, float> $probabilities
     */
    public function __construct(
        public string $choice,
        public float $confidence,
        public array $probabilities,
    ) {}

    public static function fromArray(array $data): self
    {
        if (! is_string($data['choice'] ?? null)) {
            throw new UnexpectedResponseException('A choice answer has no "choice".');
        }

        return new self(
            $data['choice'],
            self::number($data, 'confidence'),
            Payload::floats($data, 'probabilities'),
        );
    }

    public function probabilityOf(string $choice): float
    {
        return $this->probabilities[$choice] ?? 0.0;
    }
}
