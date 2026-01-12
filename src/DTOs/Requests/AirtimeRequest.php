<?php

namespace Aelura\BillGateway\DTOs\Requests;

class AirtimeRequest
{
    public function __construct(
        public string $phoneNumber,
        public string $network,
        public string $country,
        public string $currency,
        public float $amount,
        public ?string $productCode = null,
        public ?string $reference = null,
        public array $meta = [],
    ) {
    }
}
