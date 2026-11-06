<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Decisions\Questions;

/**
 * A yes/no question, answered with the probability of yes.
 */
final readonly class Noul implements Question
{
    /**
     * @param string|array<mixed> $instructions
     */
    public function __construct(
        public string|array $instructions,
        public ?string $yes = null,
        public ?string $no = null,
    ) {}

    /**
     * @param string|array<mixed> $instructions The question or statement to assess.
     * @param string|null $yes What counts as yes.
     * @param string|null $no What counts as no.
     */
    public static function make(string|array $instructions, ?string $yes = null, ?string $no = null): self
    {
        return new self($instructions, $yes, $no);
    }

    public function toArray(): array
    {
        $question = ['type' => 'noul', 'instructions' => $this->instructions];

        if ($this->yes !== null || $this->no !== null) {
            $question['criteria'] = array_filter(
                ['true' => $this->yes, 'false' => $this->no],
                fn (?string $criterion): bool => $criterion !== null,
            );
        }

        return $question;
    }
}
