<?php

declare(strict_types=1);

use CPointLabs\HostYourAI\Exceptions\ConnectionException;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\TextDeltaEvent;

/**
 * Runs a scenario of tests/Fixtures/sse-server.php and collects what the stream gave.
 *
 * @return array{text: string, seconds: float, error: Throwable|null}
 */
function streamFromSocket(string $scenario): array
{
    $server = proc_open([PHP_BINARY, __DIR__.'/../../Fixtures/sse-server.php', $scenario], [1 => ['pipe', 'w']], $pipes);
    $port = (int) fgets($pipes[1]);

    Http::allowStrayRequests();

    $text = '';
    $error = null;
    $started = microtime(true);

    try {
        $events = Prism::text()
            ->using('hostyourai', 'hyai/auto', ['base_url' => "http://127.0.0.1:{$port}/api/v1", 'timeout' => 1, 'stream_timeout' => 2])
            ->withPrompt('Say hello')
            ->asStream();

        foreach ($events as $event) {
            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
            }
        }
    } catch (Throwable $e) {
        $error = $e;
    } finally {
        proc_terminate($server, 9);
    }

    return ['text' => $text, 'seconds' => microtime(true) - $started, 'error' => $error];
}

it('stops a stalled stream at the time limit', function (): void {
    $result = streamFromSocket('stall');

    expect($result['text'])->toBe('Hi')
        ->and($result['error'])->toBeInstanceOf(ConnectionException::class)
        ->and($result['error']->getMessage())->toContain('time limit')
        ->and($result['seconds'])->toBeLessThan(3.5);
});

it('stops a stream that trickles without ever finishing a line', function (): void {
    $result = streamFromSocket('trickle');

    expect($result['error'])->toBeInstanceOf(ConnectionException::class)
        ->and($result['error']->getMessage())->toContain('time limit')
        ->and($result['seconds'])->toBeLessThan(3.5);
});

it('keeps what it read of a line when the rest arrives after a pause', function (): void {
    $result = streamFromSocket('split-line');

    expect($result['error'])->toBeNull()
        ->and($result['text'])->toBe('Hello');
});

it('finishes at DONE, even when the connection stays open', function (): void {
    $result = streamFromSocket('open-after-done');

    expect($result['error'])->toBeNull()
        ->and($result['text'])->toBe('A complete answer.')
        ->and($result['seconds'])->toBeLessThan(1.0);
});

it('reports a dropped connection as a connection error', function (): void {
    $result = streamFromSocket('dropped');

    expect($result['text'])->toBe('Hi')
        ->and($result['error'])->toBeInstanceOf(ConnectionException::class);
});

it('reports a response that never starts as a connection error', function (): void {
    $result = streamFromSocket('no-response');

    expect($result['error'])->toBeInstanceOf(ConnectionException::class)
        ->and($result['seconds'])->toBeLessThan(3.5);
});
