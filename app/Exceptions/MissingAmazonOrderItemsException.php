<?php

namespace App\Exceptions;

/**
 * A packed item has no Amazon order item ID, so the package cannot be described
 * to Amazon.
 *
 * A subclass of {@see PermanentExportException} rather than a sibling because
 * the export path has always treated this as permanent — re-importing the order
 * is the only fix, and retrying the same rows never produces one. Buy Shipping
 * reads the same identifiers a quote earlier in the same workflow, and catches
 * this to decline the offer rather than to fail an export, which is why it has
 * a name of its own.
 */
class MissingAmazonOrderItemsException extends PermanentExportException {}
