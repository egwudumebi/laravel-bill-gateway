<?php

namespace Aelura\BillGateway;

use Aelura\BillGateway\Contracts\BillProviderInterface;
use Aelura\BillGateway\Providers\FlutterwaveBillsProvider;
use Aelura\BillGateway\Providers\InterswitchBillProvider;
use Aelura\BillGateway\Providers\PaystackBillsProvider;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

class BillGatewayManager
{
    public function __construct(protected Application $app)
    {
    }

    public function driver(?string $name = null): BillProviderInterface
    {
        $name = $name ?: config('billing.default');

        return match ($name) {
            'interswitch' => $this->createInterswitchDriver(),
            'flutterwave' => $this->createFlutterwaveDriver(),
            'paystack_bills' => $this->createPaystackBillsDriver(),
            default => throw new InvalidArgumentException("Unsupported billing driver [{$name}]."),
        };
    }

    protected function createInterswitchDriver(): BillProviderInterface
    {
        $config = config('billing.providers.interswitch', []);

        return new InterswitchBillProvider($config);
    }

    protected function createFlutterwaveDriver(): BillProviderInterface
    {
        $config = config('billing.providers.flutterwave', []);

        return new FlutterwaveBillsProvider($config);
    }

    protected function createPaystackBillsDriver(): BillProviderInterface
    {
        $config = config('billing.providers.paystack_bills', []);

        return new PaystackBillsProvider($config);
    }

    public function __call(string $method, array $parameters)
    {
        return $this->driver()->{$method}(...$parameters);
    }
}
