<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Import Behavior
    |--------------------------------------------------------------------------
    */
    'behavior' => [
        // Auto-create/update products
        'auto_update_products' => env('SHIPMENT_IMPORT_AUTO_UPDATE_PRODUCTS', true),

        // Batch size for processing (number of shipments per transaction)
        'batch_size' => env('SHIPMENT_IMPORT_BATCH_SIZE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'channel' => env('SHIPMENT_IMPORT_LOG_CHANNEL', 'shipment-import'),
    ],

];
