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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Console\Command;
use LogicException;
use RuntimeException;

class FlutterwaveBillsProvider implements BillProviderInterface
{
    public function __construct(protected array $config = [])
    {
    }

    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->config, $key, $default);
    }

    protected function baseUrl(): string
    {
        return rtrim($this->getConfig('base_url', 'https://api.flutterwave.com/v3'), '/');
    }

    /**
     * Updated request method to ensure strict JSON headers.
     * This prevents the "Transaction not found" error seen in PowerShell.
     */
    protected function request(string $method, string $path, array $payload = []): array
    {
        $secret = $this->getConfig('secret_key');
        if (empty($secret)) {
            throw new RuntimeException('Flutterwave secret key is not configured');
        }

        $timeout = $this->getConfig('timeout', 30);

        try {
            $http = Http::withToken($secret)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->timeout($timeout);

            $url = $this->baseUrl() . $path;

            $response = match (strtoupper($method)) {
                'GET' => $http->get($url, $payload),
                'POST' => $http->post($url, $payload),
                default => throw new RuntimeException("Unsupported HTTP method [{$method}] for Flutterwave bills."),
            };

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'json' => $response->json() ?? [],
                'raw' => $response->body(),
            ];
        } catch (\Exception $e) {
            return [
                'ok' => false,
                'status' => 500,
                'json' => ['message' => $e->getMessage()],
                'raw' => $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    public function syncCatalog(): array
    {
        $providerName = 'flutterwave';

        $seedCategories = [
            ['id' => 'airtime', 'name' => 'Airtime'],
            ['id' => 'data', 'name' => 'Data'],
            ['id' => 'electricity', 'name' => 'Electricity'],
            ['id' => 'cable', 'name' => 'Cable TV'],
        ];

        $categoriesCount = 0;
        foreach ($seedCategories as $cat) {
            DB::table('bill_categories')->updateOrInsert(
                ['provider' => $providerName, 'external_id' => $cat['id']],
                ['name' => $cat['name'], 'updated_at' => now()] + ['created_at' => now()],
            );

            $categoriesCount++;
        }

        $billersCount = 0;
        $productsCount = 0;

        return [
            'categories' => $categoriesCount,
            'billers' => $billersCount,
            'products' => $productsCount,
        ];
    }

    public function syncCatalogScoped(string $scope = 'all', ?Command $console = null): array
    {
        $totals = ['categories' => 0, 'billers' => 0, 'products' => 0];
        
        // Sync base categories
        $categories = $this->syncCatalog();
        $totals['categories'] += $categories['categories'] ?? 0;

        $scopes = $scope === 'all' ? ['data', 'cable', 'electricity'] : [$scope];
        $cacheBillers = [];
        $cacheItems = [];

        foreach ($scopes as $seg) {
            if ($seg === 'data') {
                [$b, $p] = $this->syncDataBundles($cacheItems, $console);
                $totals['billers'] += $b;
                $totals['products'] += $p;
            } elseif ($seg === 'cable') {
                [$b, $p] = $this->syncCableProviders($cacheBillers, $cacheItems, $console);
                $totals['billers'] += $b;
                $totals['products'] += $p;
            } elseif ($seg === 'electricity') {
                [$b, $p] = $this->syncElectricUtilities($cacheBillers, $cacheItems, $console);
                $totals['billers'] += $b;
                $totals['products'] += $p;
            }
        }

        return $totals;
    }

    protected function syncDataBundles(array &$cacheItems, ?Command $console = null): array
    {
                // Fetch DATA billers from Flutterwave and persist their items as products
        $billers = $this->getBillersByCategory('DATA', $cacheItems, $console);

        $billersCount = 0;
        $productsCount = 0;

        foreach ($billers as $biller) {
            $billerCode = (string) ($biller['biller_code'] ?? $biller['code'] ?? $biller['id'] ?? '');
            $billerName = (string) ($biller['name'] ?? $biller['biller_name'] ?? '');
            $currency = (string) ($biller['currency'] ?? 'NGN');

            if ($billerCode === '') { continue; }

            DB::table('bill_providers')->updateOrInsert(
                ['provider' => 'flutterwave', 'external_id' => $billerCode],
                ['name' => $billerName !== '' ? $billerName : $billerCode, 'updated_at' => now()] + ['created_at' => now()],
            );
            $billersCount++;

            $items = $this->getBillerItems($billerCode, $cacheItems, $console);
            foreach ($items as $item) {
                $itemCode = (string) ($item['item_code'] ?? $item['itemcode'] ?? '');
                if ($itemCode === '') { continue; }

                $serviceName = (string) ($item['short_name'] ?? $item['biller_name'] ?? $item['name'] ?? 'Data Bundle');
                $amount = $item['amount'] ?? null;
                $fee = $item['fee'] ?? null;

                $amountKobo = is_numeric($amount) ? (int) round(((float) $amount) * 100) : 0;
                $feeKobo = is_numeric($fee) ? (int) round(((float) $fee) * 100) : 0;

                DB::table('bill_products')->updateOrInsert(
                    ['provider' => 'flutterwave', 'external_id' => $itemCode],
                    [
                        'name' => $serviceName,
                        'category_external_id' => 'data',
                        'biller_external_id' => $billerCode,
                        'payment_code' => $itemCode,
                        'currency_code' => $currency,
                        'amount_kobo' => $amountKobo,
                        'fee_kobo' => $feeKobo,
                        'is_airtime' => false,
                        'is_data' => true,
                        'is_power' => false,
                        'is_tv' => false,
                        'updated_at' => now(),
                    ] + ['created_at' => now()],
                );
                $productsCount++;
            }
            usleep(150000);
        }

        return [$billersCount, $productsCount];
    }

    protected function syncCableProviders(array &$cacheBillers, array &$cacheItems, ?Command $console = null): array
    {
        $candidates = ['CABLEBILLS', 'CABLETV', 'CABLE'];
        $billers = [];
        foreach ($candidates as $cat) {
            $fetched = $this->getBillersByCategory($cat, $cacheBillers, $console);
            if (!empty($fetched)) {
                $billers = $fetched;
                break;
            }
        }
        $billersCount = 0;
        $productsCount = 0;

        foreach ($billers as $biller) {
            $billerCode = (string) ($biller['biller_code'] ?? $biller['code'] ?? $biller['id'] ?? '');
            $billerName = (string) ($biller['name'] ?? $biller['biller_name'] ?? '');
            $currency = (string) ($biller['currency'] ?? 'NGN');

            if ($billerCode === '') { continue; }

            DB::table('bill_providers')->updateOrInsert(
                ['provider' => 'flutterwave', 'external_id' => $billerCode],
                ['name' => $billerName !== '' ? $billerName : $billerCode, 'updated_at' => now()] + ['created_at' => now()],
            );
            $billersCount++;

            $items = $this->getBillerItems($billerCode, $cacheItems, $console);
            foreach ($items as $item) {
                $itemCode = (string) ($item['item_code'] ?? $item['itemcode'] ?? '');
                if ($itemCode === '') { continue; }

                $serviceName = (string) ($item['short_name'] ?? $item['biller_name'] ?? $item['name'] ?? 'Cable Plan');
                $amount = $item['amount'] ?? null;
                $fee = $item['fee'] ?? null;

                $amountKobo = is_numeric($amount) ? (int) round(((float) $amount) * 100) : 0;
                $feeKobo = is_numeric($fee) ? (int) round(((float) $fee) * 100) : 0;

                DB::table('bill_products')->updateOrInsert(
                    ['provider' => 'flutterwave', 'external_id' => $itemCode],
                    [
                        'name' => $serviceName,
                        'category_external_id' => 'cable',
                        'biller_external_id' => $billerCode,
                        'payment_code' => $itemCode,
                        'currency_code' => $currency,
                        'amount_kobo' => $amountKobo,
                        'fee_kobo' => $feeKobo,
                        'is_airtime' => false,
                        'is_data' => false,
                        'is_power' => false,
                        'is_tv' => true,
                        'updated_at' => now(),
                    ] + ['created_at' => now()],
                );
                $productsCount++;
            }
            usleep(150000);
        }

        return [$billersCount, $productsCount];
    }

    protected function syncElectricUtilities(array &$cacheBillers, array &$cacheItems, ?Command $console = null): array
    {
        $candidates = ['ELECTRICITY', 'UTILITYBILLS', 'POWER'];
        $billers = [];
        foreach ($candidates as $cat) {
            $fetched = $this->getBillersByCategory($cat, $cacheBillers, $console);
            if (!empty($fetched)) {
                $billers = $fetched;
                break;
            }
        }
        $billersCount = 0;
        $productsCount = 0;

        foreach ($billers as $biller) {
            $billerCode = (string) ($biller['biller_code'] ?? $biller['code'] ?? $biller['id'] ?? '');
            $billerName = (string) ($biller['name'] ?? $biller['biller_name'] ?? '');
            $currency = (string) ($biller['currency'] ?? 'NGN');

            if ($billerCode === '') { continue; }

            DB::table('bill_providers')->updateOrInsert(
                ['provider' => 'flutterwave', 'external_id' => $billerCode],
                ['name' => $billerName !== '' ? $billerName : $billerCode, 'updated_at' => now()] + ['created_at' => now()],
            );
            $billersCount++;

            $items = $this->getBillerItems($billerCode, $cacheItems, $console);
            foreach ($items as $item) {
                $itemCode = (string) ($item['item_code'] ?? $item['itemcode'] ?? '');
                if ($itemCode === '') { continue; }

                $serviceName = (string) ($item['short_name'] ?? $item['biller_name'] ?? $item['name'] ?? 'Electricity');
                $amount = $item['amount'] ?? null;
                $fee = $item['fee'] ?? null;

                $amountKobo = is_numeric($amount) ? (int) round(((float) $amount) * 100) : 0;
                $feeKobo = is_numeric($fee) ? (int) round(((float) $fee) * 100) : 0;

                DB::table('bill_products')->updateOrInsert(
                    ['provider' => 'flutterwave', 'external_id' => $itemCode],
                    [
                        'name' => $serviceName,
                        'category_external_id' => 'electricity',
                        'biller_external_id' => $billerCode,
                        'payment_code' => $itemCode,
                        'currency_code' => $currency,
                        'amount_kobo' => $amountKobo,
                        'fee_kobo' => $feeKobo,
                        'is_airtime' => false,
                        'is_data' => false,
                        'is_power' => true,
                        'is_tv' => false,
                        'updated_at' => now(),
                    ] + ['created_at' => now()],
                );
                $productsCount++;
            }
            usleep(150000);
        }
        return [$billersCount, $productsCount];
    }

    public function validateCustomer(CustomerValidationRequest $request): CustomerValidationResult
{
    $res = $this->request('GET', '/bills/validate-customer', [
        'item_code' => $request->productCode,
        'code' => $request->billerCode,
        'customer' => $request->customerId,
        'country' => $request->country ?? $this->getConfig('country', 'NG'),
    ]);

    $data = $res['json']['data'] ?? [];
    $isSuccess = $res['ok'] && !empty($data['customer']);

    if ($isSuccess) {
        return CustomerValidationResult::success(
            customerId: $data['customer'] ?? $request->customerId,
            customerName: $data['name'] ?? null,
            provider: 'flutterwave',
            message: $data['message'] ?? 'Customer validated successfully',
            raw: $data
        );
    }

    return CustomerValidationResult::failure(
        customerId: $request->customerId,
        provider: 'flutterwave',
        message: $data['message'] ?? 'Failed to validate customer',
        errorCode: (string) ($res['json']['status'] ?? 'VALIDATION_FAILED'),
        raw: $data
    );
}

    protected function purchaseBill(string $type, string $customer, float $amount, ?string $reference = null, ?string $productCode = null, array $meta = []): BillTransactionResult
    {
        $reference = $reference ?: uniqid(strtolower($type).'_', true);

        $payload = [
            'country' => $meta['country'] ?? $this->getConfig('country', 'NG'),
            'customer' => $customer,
            'amount' => $amount,
            'type' => strtoupper($type),
            'reference' => $reference,
        ];

        if ($productCode !== null) {
            $payload['biller_code'] = $productCode;
        }

        $result = $this->request('POST', '/bills', $payload);

        return $this->mapPurchaseResult($reference, $result);
    }

    public function purchaseAirtime(AirtimeRequest $request): BillTransactionResult
    {
        return $this->purchaseBill('AIRTIME', $request->phoneNumber, $request->amount, $request->reference, $request->productCode, $request->meta);
    }

    public function purchaseData(DataRequest $request): BillTransactionResult
    {
        return $this->purchaseBill('DATA', $request->phoneNumber, $request->amount, $request->reference, $request->productCode, $request->meta);
    }

    public function payPowerBill(PowerBillRequest $request): BillTransactionResult
    {
        return $this->purchaseBill('ELECTRICITY', $request->meterNumber, $request->amount, $request->reference, $request->productCode, $request->meta);
    }

    public function payTvSubscription(TvSubscriptionRequest $request): BillTransactionResult
    {
        return $this->purchaseBill('CABLE', $request->smartcardNumber, $request->amount, $request->reference, $request->productCode, $request->meta);
    }

    public function checkTransactionStatus(string $reference): BillTransactionStatusResult
    {
        $result = $this->request('GET', '/bills/'.urlencode($reference));
        $json = $result['json'] ?? [];
        $data = $json['data'] ?? [];

        $statusText = strtolower((string) ($data['status'] ?? $json['status'] ?? ''));
        
        return BillTransactionStatusResult::success(
            status: $statusText ?: 'successful',
            reference: $reference,
            providerReference: $data['flw_ref'] ?? null,
            provider: 'flutterwave',
            raw: $json,
        );
    }

        /**
     * Fetch billers by category from Flutterwave and cache results in-memory for this run.
     */
    protected function getBillersByCategory(string $category, array &$cacheBillers = [], ?Command $console = null): array
    {
        $key = 'cat:'.strtoupper($category);
        if (isset($cacheBillers[$key])) {
            return $cacheBillers[$key];
        }

        $params = [
            'country' => $this->getConfig('country', 'NG'),
            'category' => strtoupper($category),
        ];

        $res = $this->request('GET', '/billers', $params);
        $data = $res['json']['data'] ?? [];

        if ($console) {
            $console->line("flutterwave: billers category={$category} count=".count(is_array($data) ? $data : []));
        }

        return $cacheBillers[$key] = is_array($data) ? $data : [];
    }

    /**
     * Fetch bill-items for a given biller_code and cache results in-memory for this run.
     */
    protected function getBillerItems(string $billerCode, array &$cacheItems = [], ?Command $console = null): array
    {
        $key = 'items:'.$billerCode;
        if (isset($cacheItems[$key])) {
            return $cacheItems[$key];
        }

        $params = [
            'country' => $this->getConfig('country', 'NG'),
            'biller_code' => $billerCode,
        ];

        $res = $this->request('GET', '/bill-items', $params);
        $items = $res['json']['data'] ?? [];

        if ($console) {
            $console->line("flutterwave: items biller={$billerCode} count=".count(is_array($items) ? $items : []));
        }

        return $cacheItems[$key] = is_array($items) ? $items : [];
    }

    protected function mapPurchaseResult(string $reference, array $response): BillTransactionResult
    {
        $json = $response['json'] ?? [];
        $data = $json['data'] ?? [];
        $statusText = strtolower((string) ($data['status'] ?? $json['status'] ?? ''));

        if (! $response['ok']) {
            return BillTransactionResult::failure(
                reference: $reference,
                provider: 'flutterwave',
                status: $statusText ?: 'failed',
                message: $json['message'] ?? 'Transaction failed',
                raw: $json,
            );
        }

        return BillTransactionResult::success(
            reference: $reference,
            providerReference: $data['flw_ref'] ?? null,
            amount: isset($data['amount']) ? (float) $data['amount'] : null,
            provider: 'flutterwave',
            status: $statusText ?: 'successful',
            raw: $json,
        );
    }
}