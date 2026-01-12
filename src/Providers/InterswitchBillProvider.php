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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class InterswitchBillProvider implements BillProviderInterface
{
    public function __construct(protected array $config = [])
    {
    }

    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->config, $key, $default);
    }

    protected function getAccessToken(): string
    {
        $cacheKey = 'billing.interswitch.access_token';

        return Cache::remember($cacheKey, now()->addMinutes(50), function () {
            $loginBase = rtrim($this->getConfig('login_url'), '/');
            $url = $loginBase.'/passport/oauth/token';

            $clientId = $this->getConfig('client_id');
            $clientSecret = $this->getConfig('client_secret');

            if (! $clientId || ! $clientSecret) {
                throw new RuntimeException('Interswitch client credentials are not configured.');
            }

            $response = Http::asForm()
                ->timeout($this->getConfig('timeout', 30))
                ->withBasicAuth($clientId, $clientSecret)
                ->post($url, [
                    'grant_type' => 'client_credentials',
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('Failed to obtain Interswitch access token.');
            }

            $data = $response->json();

            return $data['access_token'] ?? throw new RuntimeException('Interswitch token response missing access_token.');
        });
    }

    protected function signedRequest(string $method, string $path, array $options = []): array
    {
        $baseUrl = rtrim($this->getConfig('base_url'), '/');
        $url = $baseUrl.$path;

        $clientId = $this->getConfig('client_id');
        $terminalId = $this->getConfig('terminal_id');
        $signatureMethod = $this->getConfig('signature_method', 'SHA256');
        $timeout = $this->getConfig('timeout', 30);

        $token = $this->getAccessToken();

        $timestamp = now()->format('Y-m-d H:i:s');
        $nonce = bin2hex(random_bytes(16));

        $stringToSign = $clientId.$timestamp.$nonce.$url.strtoupper($method);
        $signature = base64_encode(hash_hmac('sha256', $stringToSign, $this->getConfig('client_secret'), true));

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'TerminalId' => $terminalId,
            'Timestamp' => $timestamp,
            'Nonce' => $nonce,
            'SignatureMethod' => $signatureMethod,
            'Signature' => $signature,
            'Accept' => 'application/json',
        ];

        $request = Http::withHeaders($headers)->timeout($timeout);

        if (isset($options['query'])) {
            $request = $request->withOptions(['query' => $options['query']]);
        }

        if (($options['as'] ?? null) === 'form') {
            $request = $request->asForm();
        }

        $response = match (strtoupper($method)) {
            'GET' => $request->get($url),
            'POST' => $request->post($url, $options['json'] ?? $options['form'] ?? []),
            default => throw new RuntimeException("Unsupported HTTP method [{$method}]."),
        };

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'json' => $response->json(),
            'raw' => $response->body(),
        ];
    }

    protected function toKobo(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public function purchaseAirtime(AirtimeRequest $request): BillTransactionResult
    {
        $payload = [
            'CustomerId' => $request->phoneNumber,
            'PaymentCode' => $request->productCode ?? Arr::get($request->meta, 'payment_code'),
            'Amount' => $this->toKobo($request->amount),
            'RequestReference' => $request->reference ?? uniqid('airtime_', true),
        ];

        $result = $this->signedRequest('POST', $this->getConfig('endpoints.purchase'), ['json' => $payload]);

        return $this->mapTransactionResult('airtime', $payload['RequestReference'], $result);
    }

    public function purchaseData(DataRequest $request): BillTransactionResult
    {
        $payload = [
            'CustomerId' => $request->phoneNumber,
            'PaymentCode' => $request->productCode,
            'Amount' => $this->toKobo($request->amount),
            'RequestReference' => $request->reference ?? uniqid('data_', true),
        ];

        $result = $this->signedRequest('POST', $this->getConfig('endpoints.purchase'), ['json' => $payload]);

        return $this->mapTransactionResult('data', $payload['RequestReference'], $result);
    }

    public function payPowerBill(PowerBillRequest $request): BillTransactionResult
    {
        $payload = [
            'CustomerId' => $request->meterNumber,
            'PaymentCode' => $request->productCode,
            'Amount' => $this->toKobo($request->amount),
            'RequestReference' => $request->reference ?? uniqid('power_', true),
        ];

        $result = $this->signedRequest('POST', $this->getConfig('endpoints.purchase'), ['json' => $payload]);

        return $this->mapTransactionResult('power', $payload['RequestReference'], $result);
    }

    public function payTvSubscription(TvSubscriptionRequest $request): BillTransactionResult
    {
        $payload = [
            'CustomerId' => $request->smartcardNumber,
            'PaymentCode' => $request->productCode,
            'Amount' => $this->toKobo($request->amount),
            'RequestReference' => $request->reference ?? uniqid('tv_', true),
        ];

        $result = $this->signedRequest('POST', $this->getConfig('endpoints.purchase'), ['json' => $payload]);

        return $this->mapTransactionResult('tv', $payload['RequestReference'], $result);
    }

    public function validateCustomer(CustomerValidationRequest $request): CustomerValidationResult
    {
        // Match legacy InterswitchService validateCustomer payload shape:
        // [
        //   'customers' => [[ 'PaymentCode' => ..., 'CustomerId' => ... ]],
        //   'TerminalId' => <terminal_id>,
        // ]
        $payload = [
            'customers' => [[
                'PaymentCode' => $request->productCode,
                'CustomerId' => $request->customerId,
            ]],
            'TerminalId' => $this->getConfig('terminal_id'),
        ];

        $result = $this->signedRequest('POST', $this->getConfig('endpoints.validate_customer'), ['json' => $payload]);

        $json = $result['json'] ?? [];
        $code = $json['ResponseCode'] ?? null;
        $grouping = $json['ResponseCodeGrouping'] ?? null;
        $success = $result['ok'] && (in_array($code, ['00', '90000'], true) || $grouping === 'SUCCESSFUL');

        if (! $success) {
            return CustomerValidationResult::failure(
                customerId: $request->customerId,
                provider: 'interswitch',
                message: $json['ResponseDescription'] ?? 'Customer validation failed.',
                errorCode: $code,
                raw: $json,
            );
        }

        return CustomerValidationResult::success(
            customerId: $request->customerId,
            customerName: $json['CustomerName'] ?? null,
            provider: 'interswitch',
            message: $json['ResponseDescription'] ?? null,
            raw: $json,
        );
    }

    public function checkTransactionStatus(string $reference): BillTransactionStatusResult
    {
        $result = $this->signedRequest('GET', $this->getConfig('endpoints.transaction_status'), [
            'query' => ['requestRef' => $reference],
        ]);

        $json = $result['json'] ?? [];
        $code = $json['ResponseCode'] ?? null;
        $grouping = $json['ResponseCodeGrouping'] ?? null;
        $success = $result['ok'] && (in_array($code, ['00', '90000'], true) || $grouping === 'SUCCESSFUL');

        if (! $success) {
            return BillTransactionStatusResult::failure(
                reference: $reference,
                provider: 'interswitch',
                status: $json['Status'] ?? ($grouping ?? 'failed'),
                message: $json['ResponseDescription'] ?? 'Unable to retrieve transaction status.',
                errorCode: $code,
                raw: $json,
            );
        }

        return BillTransactionStatusResult::success(
            status: $json['Status'] ?? ($grouping ?? 'success'),
            reference: $reference,
            providerReference: $json['TransactionRef'] ?? null,
            provider: 'interswitch',
            message: $json['ResponseDescription'] ?? null,
            raw: $json,
        );
    }

    protected function mapTransactionResult(string $type, string $reference, array $response): BillTransactionResult
    {
        $json = $response['json'] ?? [];
        $code = $json['ResponseCode'] ?? null;
        $grouping = $json['ResponseCodeGrouping'] ?? null;
        $success = $response['ok'] && (in_array($code, ['00', '90000'], true) || $grouping === 'SUCCESSFUL');

        if (! $success) {
            return BillTransactionResult::failure(
                reference: $reference,
                provider: 'interswitch',
                status: $json['Status'] ?? ($grouping ?? 'failed'),
                message: $json['ResponseDescription'] ?? 'Bill transaction failed.',
                errorCode: $code,
                raw: $json,
            );
        }

        return BillTransactionResult::success(
            reference: $reference,
            providerReference: $json['TransactionRef'] ?? null,
            amount: isset($json['Amount']) ? $json['Amount'] / 100 : null,
            provider: 'interswitch',
            status: $json['Status'] ?? ($grouping ?? 'success'),
            message: $json['ResponseDescription'] ?? null,
            raw: $json,
        );
    }

    /**
     * Sync Quickteller Bills v5 catalog into local bill_* tables.
     *
     * @return array{categories:int,billers:int,products:int}
     */
    public function syncCatalog(): array
    {
        $providerName = 'interswitch';

        // Fetch categories
        $categoriesResponse = $this->signedRequest('GET', $this->getConfig('endpoints.categories'));
        Log::info('Interswitch categories response', $categoriesResponse);


        if (! $categoriesResponse['ok']) {
            throw new RuntimeException('Failed to fetch Interswitch service categories.');
        }

        $categoriesJson = $categoriesResponse['json'] ?? [];
        // Quickteller QA returns BillerCategories with Id/Name
        $categories = $categoriesJson['BillerCategories'] ?? $categoriesJson;

        $categoriesCount = 0;
        foreach ($categories as $category) {
            $categoryId = (string) Arr::get($category, 'categoryid', Arr::get($category, 'Id'));
            $name = Arr::get($category, 'categoryname', Arr::get($category, 'Name'));

            if (! $categoryId || ! $name) {
                continue;
            }

            DB::table('bill_categories')->updateOrInsert(
                ['provider' => $providerName, 'external_id' => $categoryId],
                ['name' => $name, 'updated_at' => now()] + ['created_at' => now()],
            );

            $categoriesCount++;
        }

        // Fetch services (billers/products)
        $servicesResponse = $this->signedRequest('GET', $this->getConfig('endpoints.services'));

        if (config('app.debug') || config('billing.log_services', false)) {
            Log::info('billing-sync-services', $servicesResponse);
        }

        // Quickteller QA currently returns 417 / ResponseCode 10001 (PENDING) for /services
        // in some environments, so we treat that as "no billers/products available yet".
        if (! $servicesResponse['ok']) {
            $json = $servicesResponse['json'] ?? [];

            Log::warning('billing-sync-services-unavailable', [
                'status' => $servicesResponse['status'] ?? null,
                'response_code' => $json['ResponseCode'] ?? null,
                'response_description' => $json['ResponseDescription'] ?? null,
                'grouping' => $json['ResponseCodeGrouping'] ?? null,
            ]);

            return [
                'categories' => $categoriesCount,
                'billers' => 0,
                'products' => 0,
            ];
        }

        $servicesJson = $servicesResponse['json'] ?? [];

        // Quickteller may return:
        // - a top-level Billers array (with nested PaymentItems)
        // - a BillerList.Category[].Billers[] structure (as seen in QA)
        // - a flat services/Services array
        $billersPayload = $servicesJson['Billers'] ?? [];
        $flatServices = $servicesJson['services'] ?? ($servicesJson['Services'] ?? []);
        $billerListCategories = Arr::get($servicesJson, 'BillerList.Category', []);

        $billersCount = 0;
        $productsCount = 0;
        $seenBillers = [];

        // First, handle Billers + PaymentItems structure (most common for some Quickteller environments)
        foreach ($billersPayload as $biller) {
            $billerId = (string) Arr::get($biller, 'Id');
            $billerName = Arr::get($biller, 'Name');
            $categoryId = (string) Arr::get($biller, 'BillerCategoryId');

            if ($billerId && ! isset($seenBillers[$billerId])) {
                DB::table('bill_providers')->updateOrInsert(
                    ['provider' => $providerName, 'external_id' => $billerId],
                    ['name' => $billerName ?: $billerId, 'updated_at' => now()] + ['created_at' => now()],
                );

                $seenBillers[$billerId] = true;
                $billersCount++;
            }

            foreach (Arr::get($biller, 'PaymentItems', []) as $item) {
                $serviceId = (string) Arr::get($item, 'Id');
                $serviceName = Arr::get($item, 'Name');
                $paymentCode = Arr::get($item, 'PaymentCode');
                $currencyCode = Arr::get($item, 'CurrencyCode');

                if (! $serviceId || ! $serviceName) {
                    continue;
                }

                [$isAirtime, $isData, $isPower, $isTv] = $this->classifyProduct($categoryId, $serviceName, $billerName);

                DB::table('bill_products')->updateOrInsert(
                    ['provider' => $providerName, 'external_id' => $serviceId],
                    [
                        'name' => $serviceName,
                        'category_external_id' => $categoryId ?: null,
                        'biller_external_id' => $billerId ?: null,
                        'payment_code' => $paymentCode,
                        'currency_code' => $currencyCode,
                        'is_airtime' => $isAirtime,
                        'is_data' => $isData,
                        'is_power' => $isPower,
                        'is_tv' => $isTv,
                        'updated_at' => now(),
                    ] + ['created_at' => now()],
                );

                $productsCount++;
            }
        }

        // Handle BillerList.Category[].Billers[] structure (observed in your /services response)
        foreach ($billerListCategories as $category) {
            $categoryId = (string) Arr::get($category, 'Id');

            foreach (Arr::get($category, 'Billers', []) as $biller) {
                $billerId = (string) Arr::get($biller, 'Id');
                $billerName = Arr::get($biller, 'Name');
                $currencyCode = Arr::get($biller, 'CurrencyCode');
                $paymentCode = Arr::get($biller, 'ProductCode');

                if ($billerId && ! isset($seenBillers[$billerId])) {
                    DB::table('bill_providers')->updateOrInsert(
                        ['provider' => $providerName, 'external_id' => $billerId],
                        ['name' => $billerName ?: $billerId, 'updated_at' => now()] + ['created_at' => now()],
                    );

                    $seenBillers[$billerId] = true;
                    $billersCount++;
                }

                // Treat each biller as a product entry as well, using ProductCode as payment_code
                [$isAirtime, $isData, $isPower, $isTv] = $this->classifyProduct($categoryId, $billerName, null);

                DB::table('bill_products')->updateOrInsert(
                    ['provider' => $providerName, 'external_id' => $billerId],
                    [
                        'name' => $billerName ?: $billerId,
                        'category_external_id' => $categoryId ?: null,
                        'biller_external_id' => $billerId,
                        'payment_code' => $paymentCode,
                        'currency_code' => $currencyCode,
                        'is_airtime' => $isAirtime,
                        'is_data' => $isData,
                        'is_power' => $isPower,
                        'is_tv' => $isTv,
                        'updated_at' => now(),
                    ] + ['created_at' => now()],
                );

                $productsCount++;
            }
        }

        // Fallback: flat services array, if provided
        foreach ($flatServices as $service) {
            $serviceId = (string) Arr::get($service, 'serviceid', Arr::get($service, 'id'));
            $serviceName = Arr::get($service, 'name', Arr::get($service, 'Name'));
            $categoryId = (string) Arr::get($service, 'categoryid', Arr::get($service, 'CategoryId'));
            $billerId = (string) Arr::get($service, 'billerid', Arr::get($service, 'BillerId'));
            $billerName = Arr::get($service, 'billername', Arr::get($service, 'BillerName'));
            $paymentCode = Arr::get($service, 'paymentCode', Arr::get($service, 'paymentcode', Arr::get($service, 'PaymentCode')));
            $currencyCode = Arr::get($service, 'currencyCode', Arr::get($service, 'currencycode', Arr::get($service, 'CurrencyCode')));

            if (! $serviceId || ! $serviceName) {
                continue;
            }

            if ($billerId && ! isset($seenBillers[$billerId])) {
                DB::table('bill_providers')->updateOrInsert(
                    ['provider' => $providerName, 'external_id' => $billerId],
                    ['name' => $billerName ?: $billerId, 'updated_at' => now()] + ['created_at' => now()],
                );

                $seenBillers[$billerId] = true;
                $billersCount++;
            }

            [$isAirtime, $isData, $isPower, $isTv] = $this->classifyProduct($categoryId, $serviceName, $billerName);

            DB::table('bill_products')->updateOrInsert(
                ['provider' => $providerName, 'external_id' => $serviceId],
                [
                    'name' => $serviceName,
                    'category_external_id' => $categoryId ?: null,
                    'biller_external_id' => $billerId ?: null,
                    'payment_code' => $paymentCode,
                    'currency_code' => $currencyCode,
                    'is_airtime' => $isAirtime,
                    'is_data' => $isData,
                    'is_power' => $isPower,
                    'is_tv' => $isTv,
                    'updated_at' => now(),
                ] + ['created_at' => now()],
            );

            $productsCount++;
        }

        return [
            'categories' => $categoriesCount,
            'billers' => $billersCount,
            'products' => $productsCount,
        ];
    }

    /**
     * Best-effort classification of products into airtime/data/power/tv.
     */
    protected function classifyProduct(?string $categoryId, ?string $serviceName, ?string $billerName): array
    {
        $haystack = strtolower(($serviceName ?? '').' '.($billerName ?? '').' '.($categoryId ?? ''));

        $isAirtime = str_contains($haystack, 'airtime') || str_contains($haystack, 'mobile');
        $isData = str_contains($haystack, 'data') || str_contains($haystack, 'mb') || str_contains($haystack, 'gb');
        $isPower = str_contains($haystack, 'power') || str_contains($haystack, 'electric') || str_contains($haystack, 'ikeja') || str_contains($haystack, 'eko disco');
        $isTv = str_contains($haystack, 'tv') || str_contains($haystack, 'dstv') || str_contains($haystack, 'gotv') || str_contains($haystack, 'startimes');

        return [$isAirtime, $isData, $isPower, $isTv];
        
    }

    /**
     * Synchronize a scoped subset of the catalog.
     * Note: Interswitch API doesn't support scoped sync, so this falls back to full sync.
     *
     * @param string $scope The scope of items to sync (ignored for Interswitch)
     * @param \Illuminate\Console\Command|null $console Optional console instance for output
     * @return array{categories: int, billers: int, products: int} Counts of synchronized items
     */
    public function syncCatalogScoped(string $scope = 'all', ?Command $console = null): array
    {
        if ($console) {
            $console->warn('Interswitch provider does not support scoped sync, performing full sync instead');
        }
        
        return $this->syncCatalog();
    }
}
