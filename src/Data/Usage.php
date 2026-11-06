<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Data;

use CPointLabs\HostYourAI\Support\Payload;

final readonly class Usage
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Payload::int($data, 'input_tokens') ?? 0, Payload::int($data, 'output_tokens') ?? 0);
    }
}
