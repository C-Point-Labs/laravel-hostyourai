<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Decisions\Questions;

interface Question
{
    /**
     * The question in the shape the decision API expects.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
