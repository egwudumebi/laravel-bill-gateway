<?php

namespace Aelura\BillGateway\Contracts;

use Aelura\BillGateway\DTOs\Requests\AirtimeRequest;
use Aelura\BillGateway\DTOs\Requests\DataRequest;
use Aelura\BillGateway\DTOs\Requests\PowerBillRequest;
use Aelura\BillGateway\DTOs\Requests\TvSubscriptionRequest;
use Aelura\BillGateway\DTOs\Requests\CustomerValidationRequest;
use Aelura\BillGateway\DTOs\Results\BillTransactionResult;
use Aelura\BillGateway\DTOs\Results\CustomerValidationResult;
use Aelura\BillGateway\DTOs\Results\BillTransactionStatusResult;
use Illuminate\Console\Command;

interface BillProviderInterface
{
    public function purchaseAirtime(AirtimeRequest $request): BillTransactionResult;

    public function purchaseData(DataRequest $request): BillTransactionResult;

    public function payPowerBill(PowerBillRequest $request): BillTransactionResult;

    public function payTvSubscription(TvSubscriptionRequest $request): BillTransactionResult;

    public function validateCustomer(CustomerValidationRequest $request): CustomerValidationResult;

    public function checkTransactionStatus(string $reference): BillTransactionStatusResult;

    /**
     * Synchronize the full catalog of billers and products.
     *
     * @return array{categories: int, billers: int, products: int} Counts of synchronized items
     */
    public function syncCatalog(): array;

    /**
     * Synchronize a scoped subset of the catalog.
     *
     * @param string $scope The scope of items to sync (e.g., 'all', 'data', 'cable', 'electricity')
     * @param \Illuminate\Console\Command|null $console Optional console instance for output
     * @return array{categories: int, billers: int, products: int} Counts of synchronized items
     */
    public function syncCatalogScoped(string $scope = 'all', ?Command $console = null): array;
}