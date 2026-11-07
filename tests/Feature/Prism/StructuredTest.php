<?php

declare(strict_types=1);

use CPointLabs\HostYourAI\Exceptions\InvalidRequestException;
use CPointLabs\HostYourAI\Exceptions\ModelUnavailableException;
use CPointLabs\HostYourAI\Exceptions\StructuredOutputException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;

const STRUCTURED_URL = 'https://api.test/api/v1/chat/completions';

function okSchema(): ObjectSchema
{
    return new ObjectSchema('result', 'The result', [new BooleanSchema('ok', 'Is it ok?')], ['ok']);
}

function structuredRequest(): \Prism\Prism\Structured\PendingRequest
{
    return Prism::structured()->using('hostyourai', 'test/model')->withSchema(okSchema())->withPrompt('Is it ok?');
}

it('returns decoded structured output', function (): void {
    Http::fake([STRUCTURED_URL => Http::response(completion(['content' => '{"ok": true}', 'reasoning_content' => 'private reasoning']))]);

    $response = structuredRequest()->withMaxTokens(50)->asStructured();

    expect($response->structured)->toBe(['ok' => true])
        ->and(json_encode($response))->not->toContain('private reasoning');

    Http::assertSent(function (Request $request): bool {
        $format = $request['response_format'];

        return $format['type'] === 'json_schema'
            && $format['json_schema']['name'] === 'result'
            && $format['json_schema']['strict'] === true
            && $format['json_schema']['schema']['required'] === ['ok']
            && $request['max_tokens'] === 50
            && $request['messages'][0]['role'] === 'system'
            && str_contains($request['messages'][0]['content'], '"ok"');
    });
});

it('can turn strict mode off', function (): void {
    Http::fake([STRUCTURED_URL => Http::response(completion(['content' => '{"ok": true}']))]);

    structuredRequest()->withProviderOptions(['schema' => ['strict' => false]])->asStructured();

    Http::assertSent(fn (Request $request): bool => $request['response_format']['json_schema']['strict'] === false);
});

it('accepts JSON in a code fence', function (): void {
    Http::fake([STRUCTURED_URL => Http::response(completion(['content' => "```json\n{\"ok\": false}\n```"]))]);

    expect(structuredRequest()->asStructured()->structured)->toBe(['ok' => false]);
});

it('throws on invalid JSON without repeating the output', function (): void {
    Http::fake([STRUCTURED_URL => Http::response(completion(['content' => 'not json at all']))]);

    try {
        structuredRequest()->asStructured();
        $this->fail('Expected an exception.');
    } catch (StructuredOutputException $e) {
        expect($e->getMessage())->not->toContain('not json at all');
    }
});

it('throws when the output is a scalar', function (): void {
    Http::fake([STRUCTURED_URL => Http::response(completion(['content' => 'true']))]);

    structuredRequest()->asStructured();
})->throws(StructuredOutputException::class);

it('throws when the output was cut off', function (): void {
    Http::fake([STRUCTURED_URL => Http::response(completion(['content' => '{"ok":'], 'length'))]);

    structuredRequest()->asStructured();
})->throws(StructuredOutputException::class, 'length');

it('refuses tools', function (): void {
    Http::fake();

    $tool = Tool::as('noop')->for('Nothing')->using(fn (): string => '');

    structuredRequest()->withTools([$tool])->asStructured();
})->throws(StructuredOutputException::class, 'tools');

it('maps API errors to typed exceptions', function (): void {
    Http::fake([STRUCTURED_URL => Http::response(['error' => ['message' => 'Busy', 'code' => 'model_unavailable']], 503)]);

    structuredRequest()->asStructured();
})->throws(ModelUnavailableException::class);

it('refuses provider options it would otherwise ignore, naming them', function (array $options, string $message): void {
    Http::fake();

    expect(fn () => structuredRequest()->withProviderOptions($options)->asStructured())
        ->toThrow(InvalidRequestException::class, $message);

    Http::assertNothingSent();
})->with([
    'undocumented field' => [['knowledge_base_id' => 12], '"knowledge_base_id"'],
    'stream only' => [['reasoning' => true], '"reasoning"'],
    'unknown schema option' => [['schema' => ['name' => 'x']], '"schema.name"'],
    'not a boolean' => [['schema' => ['strict' => 'no']], 'must be true or false'],
]);

it('sends the hyai options in the body', function (): void {
    Http::fake([STRUCTURED_URL => Http::response(completion(['content' => '{"ok": true}']))]);

    structuredRequest()->withProviderOptions(['hyai' => ['task' => 'extract', 'policy' => 'quality'], 'schema' => ['strict' => false]])->asStructured();

    Http::assertSent(fn (Request $request): bool => $request['hyai'] === ['task' => 'extract', 'policy' => 'quality']
        && $request['response_format']['json_schema']['strict'] === false);
});
