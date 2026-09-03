<?php

namespace App\Console\Commands;

use App\Services\ShopifyFulfillmentSynchronizer;
use Illuminate\Console\Command;

class SyncShopifyFulfillmentsCommand extends Command
{
    protected $signature = 'packages:sync-shopify-fulfillments
        {--limit= : Maximum number of packages to check in one run}';

    protected $description = 'Sync Shopify Shipping fulfillments: un-ship voided labels and record tracking';

    public function handle(ShopifyFulfillmentSynchronizer $synchronizer): int
    {
        $limit = $this->option('limit');

        $result = $synchronizer->sync($limit === null ? null : (int) $limit);

        $this->info(
            "Checked {$result['checked']} package(s): {$result['voided']} voided, "
            ."{$result['tracked']} tracked, {$result['failed']} failed."
        );

        return self::SUCCESS;
    }
}
