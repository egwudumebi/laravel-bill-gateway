<?php

namespace Aelura\BillGateway\DTOs\Results;

class BillTransactionStatusResult
{
    public function __construct(
        public bool $success,
        public string $status,
        public string $reference,
        public ?string $providerReference,
        public string $provider,
        public ?string $message = null,
        public ?string $errorCode = null,
        public ?array $raw = null,
    ) {
    }

    public static function success(
        string $status,
        string $reference,
        ?string $providerReference,
        string $provider,
        ?string $message = null,
        ?array $raw = null,
    ): self {
        return new self(true, $status, $reference, $providerReference, $provider, $message, null, $raw);
    }

    public static function failure(
        string $reference,
        string $provider,
        ?string $status = 'failed',
        ?string $message = null,
        ?string $errorCode = null,
        ?array $raw = null,
    ): self {
        return new self(false, $status ?? 'failed', $reference, null, $provider, $message, $errorCode, $raw);
    }
}
