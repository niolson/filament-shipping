<?php

namespace App\Console\Commands;

use App\Services\ShopifyLabelVoidSynchronizer;
use Illuminate\Console\Command;

class SyncShopifyLabelVoidsCommand extends Command
{
    protected $signature = 'packages:sync-shopify-voids
        {--limit= : Maximum number of packages to check in one run}';

    protected $description = 'Un-ship packages whose Shopify Shipping label was voided in the Shopify admin';

    public function handle(ShopifyLabelVoidSynchronizer $synchronizer): int
    {
        $limit = $this->option('limit');

        $result = $synchronizer->sync($limit === null ? null : (int) $limit);

        $this->info("Checked {$result['checked']} package(s): {$result['voided']} voided, {$result['failed']} failed.");

        return self::SUCCESS;
    }
}
