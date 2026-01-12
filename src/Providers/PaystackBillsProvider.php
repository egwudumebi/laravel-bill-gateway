<?php

namespace Aelura\BillGateway\Providers;

use Aelura\BillGateway\Contracts\BillProviderInterface;
use Aelura\BillGateway\DTOs\Requests\AirtimeRequest;
use Aelura\BillGateway\DTOs\Requests\CustomerValidationRequest;
use Aelura\BillGateway\DTOs\Requests\DataRequest;
use Aelura\BillGateway\DTOs\Requests\PowerBillRequest;
use Aelura\BillGateway\DTOs\Requests\TvSubscriptionRequest;
use Aelura\BillGateway\DTOs\Results\BillTransactionResult;
use Aelura\BillGateway\DTOs\Results\BillTransactionStatusResult;
use Aelura\BillGateway\DTOs\Results\CustomerValidationResult;
use Illuminate\Console\Command;
use LogicException;

class PaystackBillsProvider implements BillProviderInterface
{
    public function __construct(protected array $config = [])
    {
    }

    protected function notImplemented(): never
    {
        throw new LogicException('Paystack Bills provider is not implemented yet.');
    }

    public function purchaseAirtime(AirtimeRequest $request): BillTransactionResult
    {
        $this->notImplemented();
    }

    public function purchaseData(DataRequest $request): BillTransactionResult
    {
        $this->notImplemented();
    }

    public function payPowerBill(PowerBillRequest $request): BillTransactionResult
    {
        $this->notImplemented();
    }

    public function payTvSubscription(TvSubscriptionRequest $request): BillTransactionResult
    {
        $this->notImplemented();
    }

    public function validateCustomer(CustomerValidationRequest $request): CustomerValidationResult
    {
        $this->notImplemented();
    }

    public function checkTransactionStatus(string $reference): BillTransactionStatusResult
    {
        $this->notImplemented();
    }

    public function syncCatalog(): array
    {
        // Paystack is used as a payment collector in this package. No catalog to sync.
        return ['categories' => 0, 'billers' => 0, 'products' => 0];
    }

    public function syncCatalogScoped(string $scope = 'all', ?Command $console = null): array
    {
        // Delegate to syncCatalog since there is no catalog for Paystack payments.
        return $this->syncCatalog();
    }
}
