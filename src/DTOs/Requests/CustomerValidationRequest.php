<?php

namespace Aelura\BillGateway\DTOs\Requests;

class CustomerValidationRequest
{
    public function __construct(
        public string $customerId,
        public ?string $billerCode = null,
        public string $productCode,
        public ?string $country = null,
        public array $meta = [],
    ) {
    }
}
