<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Prism\Maps;

use Prism\Prism\Enums\ToolChoice;

final class ToolChoiceMap
{
    /**
     * @return string|array<string, mixed>|null
     */
    public static function map(string|ToolChoice|null $toolChoice): string|array|null
    {
        if ($toolChoice === null) {
            return null;
        }

        if (is_string($toolChoice)) {
            return ['type' => 'function', 'function' => ['name' => $toolChoice]];
        }

        return match ($toolChoice) {
            ToolChoice::Auto => 'auto',
            ToolChoice::Any => 'required',
            ToolChoice::None => 'none',
        };
    }
}
