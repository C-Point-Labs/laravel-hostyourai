<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Data;

use Carbon\CarbonImmutable;
use CPointLabs\HostYourAI\Exceptions\UnexpectedResponseException;
use CPointLabs\HostYourAI\Support\Payload;
use Throwable;

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
        return new self(
            currency: Payload::string($data, 'currency') ?? self::missing('currency'),
            balance: Payload::float($data, 'balance') ?? self::missing('balance'),
            spendMonth: Payload::float($data, 'spend_month') ?? self::missing('spend_month'),
            autoTopup: Payload::bool($data, 'auto_topup') ?? self::missing('auto_topup'),
            creditLimit: Payload::float($data, 'credit_limit'),
            available: Payload::float($data, 'available'),
            unlimited: Payload::bool($data, 'unlimited'),
            asOf: self::date(Payload::string($data, 'as_of')),
        );
    }

    private static function date(?string $value): ?CarbonImmutable
    {
        try {
            return $value === null ? null : CarbonImmutable::parse($value);
        } catch (Throwable $e) {
            throw new UnexpectedResponseException('The balance has an unreadable "as_of".', previous: $e);
        }
    }

    /**
     * A money figure that is missing must not read as zero.
     */
    private static function missing(string $field): never
    {
        throw new UnexpectedResponseException("The balance has no \"{$field}\".");
    }
}
