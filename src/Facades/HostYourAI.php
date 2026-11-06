<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Facades;

use Closure;
use CPointLabs\HostYourAI\Data\Balance;
use CPointLabs\HostYourAI\Decisions\DecisionRequest;
use CPointLabs\HostYourAI\HostYourAI as Client;
use CPointLabs\HostYourAI\Testing\HostYourAIFake;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * @method static \CPointLabs\HostYourAI\Decisions\PendingDecision decision()
 * @method static \CPointLabs\HostYourAI\Data\Balance balance()
 * @method static \Illuminate\Support\Collection<int, \CPointLabs\HostYourAI\Data\Model> models()
 * @method static \Illuminate\Support\Collection<int, \CPointLabs\HostYourAI\Data\DecisionModel> decisionModels()
 *
 * @see Client
 */
class HostYourAI extends Facade
{
    /**
     * Replaces the client with a fake. No request reaches the API.
     *
     * @param array<string, mixed>|(Closure(DecisionRequest): (array<string, mixed>|Throwable))|Throwable $decisions See HostYourAIFake.
     * @param array<int, \CPointLabs\HostYourAI\Data\Model> $models
     * @param array<int, \CPointLabs\HostYourAI\Data\DecisionModel> $decisionModels
     */
    public static function fake(
        array|Closure|Throwable $decisions = [],
        ?Balance $balance = null,
        array $models = [],
        array $decisionModels = [],
    ): HostYourAIFake {
        $fake = new HostYourAIFake($decisions, $balance, $models, $decisionModels);

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
