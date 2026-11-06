<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Decisions\Answers;

final readonly class NoulAnswer extends Answer
{
    public function __construct(public float $probability) {}

    public static function fromArray(array $data): self
    {
        return new self(self::number($data, 'noul'));
    }

    public function isYes(float $threshold = 0.5): bool
    {
        return $this->probability >= $threshold;
    }
}
