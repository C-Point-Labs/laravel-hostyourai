<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Exceptions;

/**
 * The endpoint needs a personal key, not one shared with a team (403).
 */
class PersonalKeyRequiredException extends HostYourAIException {}
