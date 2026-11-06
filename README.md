# Laravel HostYourAI

[![CI](https://github.com/C-Point-Labs/laravel-hostyourai/actions/workflows/ci.yml/badge.svg)](https://github.com/C-Point-Labs/laravel-hostyourai/actions/workflows/ci.yml) [![Packagist](https://img.shields.io/packagist/v/c-point-labs/laravel-hostyourai)](https://packagist.org/packages/c-point-labs/laravel-hostyourai)

[HostYourAI](https://hostyourai.com) for Laravel. It gives you:

- a [Prism](https://prismphp.com) provider for chat: text, streaming, tool calling and structured output;
- a typed client for the decision API, with readonly DTOs for Choice, Noul and Score questions;
- typed exceptions for API errors, with `Retry-After` support;
- a test fake.

This is an independent open source package. It is not affiliated with HostYourAI B.V.

## Install

Requires PHP 8.2+ and Laravel 12.61+ or 13.12+.

```bash
composer require c-point-labs/laravel-hostyourai
```

Add your key to `.env`:

```dotenv
HOSTYOURAI_API_KEY=your-key
# Optional, this is the default:
HOSTYOURAI_BASE_URL=https://hostyourai.com/api/v1
```

To change timeouts, retries or the default decision model, publish the config:

```bash
php artisan vendor:publish --tag=hostyourai-config
```

| Key | Default | Meaning |
| --- | --- | --- |
| `api_key` | `HOSTYOURAI_API_KEY` | Your API key |
| `base_url` | `HOSTYOURAI_BASE_URL` | URL for chat, models and balance |
| `decision_url` | derived from `base_url` | URL for the decision API |
| `timeout` | `30` | Seconds per request |
| `connect_timeout` | `5` | Seconds to connect |
| `stream_timeout` | `120` | Total seconds a stream may run |
| `retry.times` | `0` | Automatic retries on 429 and 503 |
| `retry.max_wait` | `5` | Longest `Retry-After`, in seconds, that is still retried |
| `decision_model` | `hyai/loes-beslis` | Model used when you don't call `->model()` |

You don't need an entry in `config/prism.php`.

## Chat

Use Prism with the `hostyourai` provider. `hyai/auto` lets the router pick a model.

```php
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using('hostyourai', 'hyai/auto')
    ->withSystemPrompt('Answer briefly.')
    ->withPrompt('What is the capital of the Netherlands?')
    ->withMaxTokens(300)
    ->asText();

echo $response->text;
echo $response->meta->model; // The model that answered
```

### Streaming

`VisibleText` gives you only the text meant for the user:

```php
use CPointLabs\HostYourAI\Prism\VisibleText;

$stream = Prism::text()
    ->using('hostyourai', 'hyai/auto')
    ->withPrompt('Tell me about Groningen.')
    ->asStream();

foreach (VisibleText::from($stream) as $delta) {
    echo $delta;
}
```

Or handle every Prism event yourself:

```php
use Prism\Prism\Streaming\Events\TextDeltaEvent;

foreach ($stream as $event) {
    if ($event instanceof TextDeltaEvent) {
        echo $event->delta;
    }
}
```

A stream that ends early or runs past `stream_timeout` throws a `ConnectionException`, so you can show a fallback message.

### Reasoning

Some models reason before they answer. That text is kept away from your users by default:

- `$response->text` and text deltas hold the answer only. Leading blank lines are removed.
- Text and structured responses leave reasoning out completely.
- Streams emit it as a `ThinkingEvent`, which you only see if you read `$event->delta` yourself.
- `toArray()` on those events leaves the text out, so `asEventStreamResponse()` and `asDataStreamResponse()` send no reasoning text to the browser.
- `asBroadcast()` throws on those events. To broadcast, turn them off, which works for any stream:

```php
Prism::text()
    ->using('hostyourai', 'hyai/auto')
    ->withProviderOptions(['reasoning' => false])
    ->withPrompt('Hello')
    ->asBroadcast($channel);
```

Reasoning can use up your `max_tokens`. You then get empty text and `FinishReason::Length`, so allow for this when you set a low limit.

### Tool calling

```php
use Prism\Prism\Facades\Tool;

$weather = Tool::as('weather')
    ->for('Get the current weather')
    ->withStringParameter('city', 'The city')
    ->using(fn (string $city): string => "It is 18 degrees in {$city}.");

$response = Prism::text()
    ->using('hostyourai', 'hyai/auto')
    ->withTools([$weather])
    ->withMaxSteps(3)
    ->withPrompt('What is the weather in Groningen?')
    ->asText();
```

`HostYourAI::models()` shows which models support tools.

### Structured output

```php
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;

$schema = new ObjectSchema('result', 'The result', [
    new BooleanSchema('in_europe', 'Is it in Europe?'),
], ['in_europe']);

$response = Prism::structured()
    ->using('hostyourai', 'mistral-small-4-119b')
    ->withSchema($schema)
    ->withPrompt('Is Groningen in Europe?')
    ->withMaxTokens(100)
    ->asStructured();

$response->structured['in_europe'];
```

- Use a named model and test it for your schema: response times and support differ per model, and `hyai/auto` may pick a slower one.
- Invalid or cut-off output throws a `StructuredOutputException`.
- Set `withProviderOptions(['schema' => ['strict' => false]])` to turn off strict mode.
- Tools can't be combined with structured output in this provider.

## Decisions

The decision API returns typed judgments instead of text: your code gets a choice, a probability or a score.

```php
use CPointLabs\HostYourAI\Decisions\Questions\Choice;
use CPointLabs\HostYourAI\Decisions\Questions\Noul;
use CPointLabs\HostYourAI\Decisions\Questions\Score;
use CPointLabs\HostYourAI\Facades\HostYourAI;

$result = HostYourAI::decision()
    ->model('hyai/loes-beslis')
    ->state('I was charged twice for the same invoice.')
    ->ask('team', Choice::make('Which team handles this?', [
        'billing' => 'Invoices and payments',
        'technical' => 'Bugs and integrations',
        'other' => 'Anything else',
    ]))
    ->ask('refund', Noul::make('Is a refund requested?'))
    ->ask('urgency', Score::make('How urgent is this?', ['Can wait', 'Today', 'Immediately']))
    ->send();

$result->choice('team')->choice;         // 'billing'
$result->choice('team')->confidence;     // 0.88
$result->choice('team')->probabilities;  // ['billing' => 0.92, ...]

$result->noul('refund')->probability;    // 0.24
$result->noul('refund')->isYes(0.8);     // false

$result->score('urgency')->score;        // 1.7
$result->score('urgency')->confidence;   // 0.55
$result->score('urgency')->legend;       // [0 => 'Can wait', ...]
$result->score('urgency')->probabilities;

$result->usage->inputTokens;
$result->costCredits;
```

- `state()` takes a string or an array.
- `Noul::make($question, yes: '...', no: '...')` spells out what counts as yes or no.
- `->timeout(5)` sets the timeout for one call.
- The builder checks the limits before sending: 2 to 16 choice options, 1 to 10 score levels and at most 16 questions.

A probability is not a guarantee, so validate your thresholds on your own data.

Models can be cold, and limits differ per model. Check them first:

```php
$ready = HostYourAI::decisionModels()->where('ready', true);

$ready->first()->id;
$ready->first()->contextTokensPerQuestion;
```

### Ensembles

Ensembles are not supported yet.

## Account

```php
$balance = HostYourAI::balance();

$balance->balance;     // 41.25
$balance->spendMonth;  // 12.80

foreach (HostYourAI::models() as $model) {
    $model->id;
    $model->contextLength;
    $model->supportsTools;
    $model->supportsImages;
}
```

`balance()` needs a personal key.

## Errors

Every API error is a typed exception that extends `HostYourAIException`, which extends Prism's `PrismException`. This holds for decisions and for Prism calls.

| Exception | When |
| --- | --- |
| `AuthenticationException` | 401: the key is missing, invalid or revoked |
| `BudgetExceededException` | 402: credit or team budget used up |
| `ModelNotAllowedException` | 403: this key may not use the model |
| `PersonalKeyRequiredException` | 403: the endpoint needs a personal key |
| `InvalidRequestException` | 400, 404, 413, 422 |
| `RateLimitedException` | 429 |
| `ModelUnavailableException` | 503: the model can't serve this request right now |
| `ServerException` | Other 5xx |
| `ConnectionException` | Network failure, timeout, or a stream that ended early |
| `ConfigurationException` | No API key configured |
| `UnexpectedResponseException` | A response the package can't read |
| `StructuredOutputException` | Invalid or incomplete structured output |

Each one has `status`, `errorType`, `errorCode`, `requestId`, `retryAfter` and `retryAt()`. `retryAfter` is null when the API sends no `Retry-After`.

```php
use CPointLabs\HostYourAI\Exceptions\HostYourAIException;
use CPointLabs\HostYourAI\Exceptions\ModelUnavailableException;

try {
    $answer = Prism::text()->using('hostyourai', 'hyai/auto')->withPrompt($prompt)->asText()->text;
} catch (ModelUnavailableException $e) {
    $tryAgainAt = $e->retryAt();
} catch (HostYourAIException $e) {
    report($e);
}
```

Retries are off by default. With `retry.times` set, 429 and 503 responses are retried after `Retry-After` (or 1 second if absent), unless that wait is longer than `retry.max_wait`.

## Privacy

The package doesn't log anything. Prompts and keys never appear in its exception messages, and the key and decision state are marked as `#[\SensitiveParameter]`, so PHP hides them from stack traces. Your own logging, error tracking and HTTP monitoring are still yours to check.

## Testing your app

Decisions, balance and models:

```php
use CPointLabs\HostYourAI\Exceptions\BudgetExceededException;
use CPointLabs\HostYourAI\Facades\HostYourAI;

$fake = HostYourAI::fake([
    'refund' => 0.9,        // Noul or Score
    'team' => 'billing',    // Choice
]);

// ...

$fake->assertDecided(fn ($request) => $request->state === 'Refund please');
$fake->assertDecisionCount(1);
$fake->assertNothingDecided();

HostYourAI::fake(new BudgetExceededException('Budget used up', 402));
HostYourAI::fake(fn ($request) => ['refund' => 0.1]);
```

Chat uses Prism's own [`Prism::fake()`](https://prismphp.com/core-concepts/testing.html).

## Development

```bash
composer install
composer check
```

`composer check` runs Pint, PHPStan and Pest. PHPStan runs at level max with bleeding edge, strict rules, deprecation rules, Larastan, ShipMonk rules and type coverage, with no baseline.

A small live smoke test calls the real API with neutral text and low token limits. It is skipped unless you ask for it:

```bash
HOSTYOURAI_LIVE_TESTS=true HOSTYOURAI_API_KEY=your-key vendor/bin/pest tests/Live
```

`HOSTYOURAI_LIVE_TESTS` and `HOSTYOURAI_API_KEY` must be in the actual process environment. Some local PHP setups, like Lerd, don't pass environment variables through to PHP, and the live tests are then skipped.

## License

MIT. See [LICENSE](LICENSE).
