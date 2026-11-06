<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI;

use CPointLabs\HostYourAI\Contracts\SendsDecisions;
use CPointLabs\HostYourAI\Data\Balance;
use CPointLabs\HostYourAI\Data\DecisionModel;
use CPointLabs\HostYourAI\Data\Model;
use CPointLabs\HostYourAI\Decisions\DecisionRequest;
use CPointLabs\HostYourAI\Decisions\DecisionResult;
use CPointLabs\HostYourAI\Decisions\PendingDecision;
use CPointLabs\HostYourAI\Exceptions\ExceptionFactory;
use CPointLabs\HostYourAI\Exceptions\UnexpectedResponseException;
use CPointLabs\HostYourAI\Http\ClientFactory;
use CPointLabs\HostYourAI\Support\Payload;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;

class HostYourAI implements SendsDecisions
{
    /** The key to pass to Prism: Prism::text()->using(HostYourAI::PROVIDER, $model). */
    public const PROVIDER = 'hostyourai';

    public function __construct(
        protected readonly ClientFactory $clients,
        protected readonly ?string $defaultDecisionModel = null,
    ) {}

    public function decision(): PendingDecision
    {
        return new PendingDecision($this, $this->defaultDecisionModel);
    }

    public function sendDecision(DecisionRequest $request): DecisionResult
    {
        $client = $this->clients->decisions();

        if ($request->timeout !== null) {
            $client->timeout($request->timeout);
        }

        return DecisionResult::fromArray($this->call(fn () => $client->post('systemone', $request->toArray())));
    }

    public function balance(): Balance
    {
        return Balance::fromArray($this->call(fn () => $this->clients->chat()->get('balance')));
    }

    /**
     * The chat models available to this key.
     *
     * @return Collection<int, Model>
     */
    public function models(): Collection
    {
        return collect(Payload::maps($this->call(fn () => $this->clients->chat()->get('models')), 'data'))
            ->map(fn (array $model): Model => Model::fromArray($model));
    }

    /**
     * The decision models, with whether each one is ready to answer now.
     *
     * @return Collection<int, DecisionModel>
     */
    public function decisionModels(): Collection
    {
        return collect(Payload::maps($this->call(fn () => $this->clients->decisions()->get('models')), 'data'))
            ->map(fn (array $model): DecisionModel => DecisionModel::fromArray($model));
    }

    /**
     * @param callable(): \Illuminate\Http\Client\Response $send
     * @return array<string, mixed>
     */
    private function call(callable $send): array
    {
        try {
            $data = $send()->json();
        } catch (RequestException $e) {
            throw ExceptionFactory::fromRequestException($e);
        } catch (HttpConnectionException $e) {
            throw ExceptionFactory::fromConnectionError($e);
        }

        if (! is_array($data)) {
            throw new UnexpectedResponseException('HostYourAI returned a response that is not JSON.');
        }

        return Payload::stringKeys($data);
    }
}
