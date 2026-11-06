<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Data;

use Carbon\CarbonImmutable;
use CPointLabs\HostYourAI\Support\Payload;

final readonly class Balance
{
    public function __construct(
        public string $currency,
        public float $balance,
        public float $spendMonth,
        public bool $autoTopup,
        public ?float $creditLimit = null,
        public ?float $available = null,
        public ?bool $unlimited = null,
        public ?CarbonImmutable $asOf = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $asOf = Payload::string($data, 'as_of');

        return new self(
            currency: Payload::string($data, 'currency') ?? 'EUR',
            balance: Payload::float($data, 'balance') ?? 0.0,
            spendMonth: Payload::float($data, 'spend_month') ?? 0.0,
            autoTopup: Payload::bool($data, 'auto_topup') ?? false,
            creditLimit: Payload::float($data, 'credit_limit'),
            available: Payload::float($data, 'available'),
            unlimited: Payload::bool($data, 'unlimited'),
            asOf: $asOf === null ? null : CarbonImmutable::parse($asOf),
        );
    }
}
