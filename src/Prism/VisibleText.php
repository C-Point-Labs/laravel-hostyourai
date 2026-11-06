<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Prism;

use Generator;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;

/**
 * Reduces a Prism stream to the text a user should see.
 */
final class VisibleText
{
    /**
     * @param iterable<StreamEvent> $events
     * @return Generator<int, string>
     */
    public static function from(iterable $events): Generator
    {
        foreach ($events as $event) {
            if ($event instanceof TextDeltaEvent) {
                yield $event->delta;
            }
        }
    }
}
