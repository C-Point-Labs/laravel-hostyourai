<?php

declare(strict_types=1);

return [

    /*
    | Your HostYourAI API key. Keep it in .env and never commit it.
    */
    'api_key' => env('HOSTYOURAI_API_KEY'),

    /*
    | The OpenAI-compatible base URL, used for chat, models and balance.
    */
    'base_url' => env('HOSTYOURAI_BASE_URL', 'https://hostyourai.com/api/v1'),

    /*
    | The decision API base URL. When null it is derived from the host of
    | base_url as {scheme}://{host}/api/decision/v1.
    */
    'decision_url' => null,

    /*
    | Timeouts in seconds. "timeout" applies to a single request. A stream also
    | ends with a ConnectionException once "stream_timeout" has passed in total.
    */
    'timeout' => 30,
    'connect_timeout' => 5,
    'stream_timeout' => 120,

    /*
    | Automatic retries on 429 and 503 honour Retry-After, and are skipped when
    | the requested wait is longer than "max_wait" seconds. Off by default, so
    | a request never blocks for longer than you expect.
    */
    'retry' => [
        'times' => 0,
        'max_wait' => 5,
    ],

    /*
    | The model used by HostYourAI::decision() unless you call ->model().
    */
    'decision_model' => 'hyai/loes-beslis',

];
