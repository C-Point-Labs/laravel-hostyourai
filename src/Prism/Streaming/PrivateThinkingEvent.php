<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Prism\Streaming;

use Prism\Prism\Streaming\Events\ThinkingEvent;

/**
 * A model's reasoning, for code that asks for it: read $event->delta.
 *
 * toArray() leaves the text out. Prism's SSE and data protocol responses
 * serialize events through it, so they never send reasoning to a browser.
 */
final readonly class PrivateThinkingEvent extends ThinkingEvent
{
    public function toArray(): array
    {
        return [...parent::toArray(), 'delta' => '', 'summary' => null];
    }
}
