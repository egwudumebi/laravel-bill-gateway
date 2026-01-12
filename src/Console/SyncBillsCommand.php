<?php

namespace Aelura\BillGateway\Console;

use Aelura\BillGateway\BillGatewayManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncBillsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:sync {--driver=} {--scope=all : Scope to sync (all, data, cable, electricity)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync bill catalogs with upstream providers';

    public function handle(BillGatewayManager $manager): int
    {
        $driver = $this->option('driver') ?: config('billing.default');
        $scope = $this->option('scope') ?: 'all';

        $provider = $manager->driver($driver);

        $this->info("Starting sync with {$driver} [scope={$scope}]...");

        if (method_exists($provider, 'syncCatalogScoped')) {
            $result = $provider->syncCatalogScoped($scope, $this);
        } else {
            $this->warn("Scoped sync not supported for {$driver}, falling back to full sync");
            $result = $provider->syncCatalog();
        }

        $categories = $result['categories'] ?? 0;
        $billers = $result['billers'] ?? 0;
        $products = $result['products'] ?? 0;

        $this->info("Sync complete: categories={$categories}, billers={$billers}, products={$products}.");

        return self::SUCCESS;
    }
}
