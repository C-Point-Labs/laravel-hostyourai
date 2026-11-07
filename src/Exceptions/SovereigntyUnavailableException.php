<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Exceptions;

/**
 * EU only is on and the model has no sovereign capacity right now (503). Don't fall back to a non-EU provider.
 */
class SovereigntyUnavailableException extends ModelUnavailableException {}
