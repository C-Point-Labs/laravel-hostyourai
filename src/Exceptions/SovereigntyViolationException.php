<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Exceptions;

/**
 * EU only is on and the machine this request landed on is not sovereign (403).
 */
class SovereigntyViolationException extends HostYourAIException {}
