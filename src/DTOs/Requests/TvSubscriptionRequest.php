<?php

namespace Aelura\BillGateway\DTOs\Requests;

class TvSubscriptionRequest
{
    public function __construct(
        public string $smartcardNumber,
        public string $provider,
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
