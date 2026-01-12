<?php

namespace Aelura\BillGateway\DTOs\Results;

class BillTransactionResult
{
    public function __construct(
        public bool $success,
        public string $status,
        public string $reference,
        public ?string $providerReference,
        public ?float $amount,
        public string $provider,
        public ?string $message = null,
        public ?string $errorCode = null,
        public ?array $raw = null,
    ) {
    }

    public static function success(
        string $reference,
        ?string $providerReference,
        ?float $amount,
        string $provider,
        ?string $status = 'success',
        ?string $message = null,
        ?array $raw = null,
    ): self {
        return new self(true, $status ?? 'success', $reference, $providerReference, $amount, $provider, $message, null, $raw);
    }

    public static function failure(
        string $reference,
        string $provider,
        ?string $status = 'failed',
        ?string $message = null,
        ?string $errorCode = null,
        ?array $raw = null,
    ): self {
        return new self(false, $status ?? 'failed', $reference, null, null, $provider, $message, $errorCode, $raw);
    }
}
