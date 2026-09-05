<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromReturnNewRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap',
        __DIR__.'/config',
        __DIR__.'/public',
        __DIR__.'/resources',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    // uncomment to reach your current PHP version
    // ->withPhpSets()
    //
    // Levels are indexes into Rector's ordered rule lists, safest rule first.
    // 43 is ClosureReturnTypeRector, the last of the two rules that carry this
    // codebase: it and AddArrowFunctionReturnTypeRector account for 104 of the
    // 119 changes the full list wants.
    //
    // The cut is at 43 because of what sits above it. Rules 44+ infer
    // *parameter* types for closures from the call sites they can see, which on
    // a framework that hands callbacks whatever it likes is guesswork — at the
    // full level they narrowed an `Illuminate\Http\Client\Request` to `array`
    // because the body used `$request['key']`, which would have thrown at
    // runtime. Param inference below 43 is confined to private methods, where
    // every call site is in the same file and the inference actually holds.
    ->withTypeCoverageLevel(43)
    // Dead code stays at 0 deliberately; don't raise it without re-reading this.
    // At its full level it wants 25 files, and the damage outweighs the finds:
    // RemoveNullNamedArgOnNullDefaultParamRector strips explicit `service: null`
    // and `boxSizeId: null` from 9 sites that pass them on purpose — in
    // ShopifyAdapter it deletes the four-line comment citing ADR-0003 along with
    // the argument — and RemoveUnusedConstructorParamRector relocates
    // `@phpstan-ignore` comments onto unrelated lines in PackageExportTest.
    // RemoveAlwaysTrueIfConditionRector is correct today about the BoxSizeType
    // exhaustion in UspsAdapter, but collapsing it drops a safe default that a
    // fourth enum case would need. The three genuine finds from that dry-run
    // were applied by hand in the commit that added this comment.
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0)
    ->withSkip([
        // Hints a factory's return type as the interface its anonymous class
        // implements, which hides members the caller reads off the concrete
        // class. `countingRatesWorkflow()` in ShipTest carries a comment saying
        // its type is deliberately left off so `->calls` stays reachable; the
        // rule annotated it anyway. It found one call site in the whole
        // codebase and that one was wrong.
        ReturnTypeFromReturnNewRector::class,
    ]);
