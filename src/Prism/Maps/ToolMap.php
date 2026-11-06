<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Prism\Maps;

use Prism\Prism\Tool;
use stdClass;

final class ToolMap
{
    /**
     * @param array<Tool> $tools
     * @return list<array<string, mixed>>
     */
    public static function map(array $tools): array
    {
        return array_values(array_map(function (Tool $tool): array {
            $properties = $tool->parametersAsArray();

            return [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => [
                        'type' => 'object',
                        'properties' => $properties === [] ? new stdClass : $properties,
                        'required' => $tool->requiredParameters(),
                    ],
                ],
            ];
        }, $tools));
    }
}
