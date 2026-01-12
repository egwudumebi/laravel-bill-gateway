<?php

namespace Aelura\BillGateway\DTOs\Requests;

class PowerBillRequest
{
    public function __construct(
        public string $meterNumber,
        public string $disco,
        public string $country,
        public string $currency,
        public float $amount,
        public string $productCode,
        public ?string $customerName = null,
        public ?string $reference = null,
        public array $meta = [],
    ) {
    }
}
