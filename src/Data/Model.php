<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Data;

use CPointLabs\HostYourAI\Support\Payload;

/**
 * A chat model from GET /models.
 */
final readonly class Model
{
    public function __construct(
        public string $id,
        public ?string $displayName = null,
        public bool $available = false,
        public ?int $contextLength = null,
        public ?int $servedContextLength = null,
        public bool $supportsTools = false,
        public bool $supportsImages = false,
        public ?string $modality = null,
        public ?int $coldStartSeconds = null,
        public ?bool $sovereigntySafe = null,
        public ?float $inputPerMillion = null,
        public ?float $outputPerMillion = null,
        public ?string $currency = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data, 'id') ?? '',
            displayName: Payload::string($data, 'display_name'),
            available: Payload::bool($data, 'available') ?? false,
            contextLength: Payload::int($data, 'context_length'),
            servedContextLength: Payload::int($data, 'served_context_length'),
            supportsTools: Payload::bool($data, 'supports_tools') ?? false,
            supportsImages: Payload::bool($data, 'supports_images') ?? false,
            modality: Payload::string($data, 'modality'),
            coldStartSeconds: Payload::int($data, 'cold_start_seconds'),
            sovereigntySafe: Payload::bool($data, 'sovereignty_safe'),
            inputPerMillion: Payload::float($data, 'pricing.input_per_million'),
            outputPerMillion: Payload::float($data, 'pricing.output_per_million'),
            currency: Payload::string($data, 'pricing.currency'),
        );
    }
}
