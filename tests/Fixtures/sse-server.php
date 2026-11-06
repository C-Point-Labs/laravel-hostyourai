<?php

declare(strict_types=1);

/*
 * A real socket for the behaviour Http::fake() can't show: reads that block, bytes
 * that trickle and connections that stay open. Serves one request, as scenario $argv[1].
 */

$chunk = fn (string $content, ?string $finishReason = null): string => 'data: '.json_encode([
    'model' => 'test/model',
    'choices' => [['delta' => ['content' => $content], 'finish_reason' => $finishReason]],
])."\n\n";

$server = stream_socket_server('tcp://127.0.0.1:0');
echo substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1).PHP_EOL;

$client = stream_socket_accept($server, 30);

while (($line = fgets($client)) !== false && trim($line) !== '') {
    // Skips the request.
}

$send = function (string $bytes) use ($client): void {
    fwrite($client, $bytes);
    fflush($client);
};

if ($argv[1] === 'no-response') {
    sleep(30);

    exit;
}

$send("HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\n\r\n");

match ($argv[1]) {
    'stall' => (function () use ($send, $chunk): void {
        $send($chunk('Hi'));
        sleep(30);
    })(),
    'split-line' => (function () use ($send, $chunk): void {
        $line = $chunk('Hello');
        $send(substr($line, 0, 30));
        usleep(1500000);
        $send(substr($line, 30).$chunk('', 'stop')."data: [DONE]\n\n");
    })(),
    'trickle' => (function () use ($send): void {
        for ($i = 0; $i < 150; $i++) {
            $send('x');
            usleep(200000);
        }
    })(),
    'open-after-done' => (function () use ($send, $chunk): void {
        $send($chunk('A complete answer.', 'stop')."data: [DONE]\n\n");
        sleep(30);
    })(),
    'dropped' => $send($chunk('Hi')),
};
