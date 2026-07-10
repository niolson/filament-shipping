<?php

use App\Services\ShipmentImport\RawSqlGuard;

it('allows the permitted statement type', function (array $allowed, string $sql): void {
    expect(fn () => RawSqlGuard::assertStatementType($sql, $allowed, 'test query'))
        ->not->toThrow(InvalidArgumentException::class);
})->with([
    'select read' => [RawSqlGuard::READ, 'SELECT * FROM shipments WHERE id = :id'],
    'lowercase select' => [RawSqlGuard::READ, 'select id, name from shipments'],
    'update mark-exported' => [RawSqlGuard::MARK_EXPORTED, "update shipments set exported = 'y' where id = :shipment_reference"],
    'insert mark-exported' => [RawSqlGuard::MARK_EXPORTED, 'INSERT INTO export_log (ref) VALUES (:shipment_reference)'],
    'insert export' => [RawSqlGuard::EXPORT, 'insert into package_export (tracking_num) values (:tracking_number)'],
    'trailing semicolon' => [RawSqlGuard::READ, 'SELECT 1;'],
    'leading comment' => [RawSqlGuard::READ, "-- pull open orders\nSELECT * FROM shipments"],
]);

it('rejects a disallowed statement type', function (array $allowed, string $sql): void {
    expect(fn () => RawSqlGuard::assertStatementType($sql, $allowed, 'test query'))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'delete as read' => [RawSqlGuard::READ, 'DELETE FROM shipments WHERE 1=1'],
    'delete as mark-exported' => [RawSqlGuard::MARK_EXPORTED, 'DELETE FROM shipments WHERE id = :shipment_reference'],
    'drop as export' => [RawSqlGuard::EXPORT, 'DROP TABLE package_export'],
    'truncate' => [RawSqlGuard::MARK_EXPORTED, 'TRUNCATE shipments'],
    'create as export' => [RawSqlGuard::EXPORT, 'CREATE TABLE evil (id INT)'],
    'cte hiding a delete' => [RawSqlGuard::READ, 'WITH x AS (SELECT 1) DELETE FROM shipments'],
    'comment hiding a delete' => [RawSqlGuard::READ, '/* SELECT */ DELETE FROM shipments'],
    'statement stacking' => [RawSqlGuard::MARK_EXPORTED, "UPDATE shipments SET exported = 'y' WHERE id = :shipment_reference; DROP TABLE shipments"],
]);

it('allows an empty or whitespace-only value (nullable field)', function (): void {
    RawSqlGuard::assertStatementType('', RawSqlGuard::READ, 'test query');
    RawSqlGuard::assertStatementType('   ', RawSqlGuard::READ, 'test query');
})->throwsNoExceptions();
