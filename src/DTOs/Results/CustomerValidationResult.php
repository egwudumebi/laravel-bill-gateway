<?php

namespace Aelura\BillGateway\DTOs\Results;

class CustomerValidationResult
{
    public function __construct(
        public bool $success,
        public string $customerId,
        public ?string $customerName,
        public string $provider,
        public ?string $message = null,
        public ?string $errorCode = null,
        public ?array $raw = null,
    ) {
    }

    public static function success(
        string $customerId,
        ?string $customerName,
        string $provider,
        ?string $message = null,
        ?array $raw = null,
    ): self {
        return new self(true, $customerId, $customerName, $provider, $message, null, $raw);
    }

    public static function failure(
        string $customerId,
        string $provider,
        ?string $message = null,
        ?string $errorCode = null,
        ?array $raw = null,
    ): self {
        return new self(false, $customerId, null, $provider, $message, $errorCode, $raw);
    }
}
