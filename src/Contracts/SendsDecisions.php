<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Contracts;

use CPointLabs\HostYourAI\Decisions\DecisionRequest;
use CPointLabs\HostYourAI\Decisions\DecisionResult;

interface SendsDecisions
{
    public function sendDecision(DecisionRequest $request): DecisionResult;
}
