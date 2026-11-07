<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Exceptions;

/**
 * The team's router could not be read, so the request was refused rather than served without its guards (503). Try again, and don't fall back to a provider outside those guards.
 */
class RouterUnavailableException extends ModelUnavailableException {}
