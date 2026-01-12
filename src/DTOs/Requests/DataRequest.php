<?php

namespace Aelura\BillGateway\DTOs\Requests;

class DataRequest
{
    public function __construct(
        public string $phoneNumber,
        public string $network,
        public string $country,
        public string $currency,
        public float $amount,
        public string $productCode,
        public ?string $reference = null,
        public array $meta = [],
    ) {
    }
}
