<?php

namespace App\Services\ShipmentImport;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Constrains admin-authored raw SQL (the DataSource query fields) to a single
 * statement of an allowed type, so a field meant to SELECT/UPDATE/INSERT cannot
 * be turned into a DELETE/DROP/TRUNCATE/ALTER/etc. These queries run
 * automatically on a schedule against a configured — possibly a customer's
 * production — database, so the leading statement type is the meaningful trust
 * boundary. See security review issue 07.
 *
 * Reads are limited to plain SELECT (not WITH: a CTE can lead a DELETE/UPDATE on
 * MySQL 8 / Postgres, which would defeat the restriction).
 */
class RawSqlGuard
{
    /** @var list<string> Allowed leading keyword for read (fetch) queries. */
    public const READ = ['SELECT'];

    /** @var list<string> Allowed leading keyword for the mark-exported write. */
    public const MARK_EXPORTED = ['UPDATE', 'INSERT'];

    /** @var list<string> Allowed leading keyword for the export write. */
    public const EXPORT = ['INSERT', 'UPDATE'];

    /**
     * @param  list<string>  $allowedKeywords  Upper-case leading keywords.
     *
     * @throws InvalidArgumentException
     */
    public static function assertStatementType(string $sql, array $allowedKeywords, string $fieldLabel): void
    {
        $error = self::validate($sql, $allowedKeywords, $fieldLabel);

        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }
    }

    /**
     * A validation rule enforcing the same policy on a form field. Returned as a
     * ValidationRule object (not a bare closure) so Filament passes it straight to
     * the validator instead of trying to evaluate it as a dynamic-rule closure.
     *
     * @param  list<string>  $allowedKeywords
     */
    public static function rule(array $allowedKeywords, string $fieldLabel): ValidationRule
    {
        return new class($allowedKeywords, $fieldLabel) implements ValidationRule
        {
            /**
             * @param  list<string>  $allowedKeywords
             */
            public function __construct(
                private readonly array $allowedKeywords,
                private readonly string $fieldLabel,
            ) {}

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if (! is_string($value)) {
                    return;
                }

                try {
                    RawSqlGuard::assertStatementType($value, $this->allowedKeywords, $this->fieldLabel);
                } catch (InvalidArgumentException $e) {
                    $fail($e->getMessage());
                }
            }
        };
    }

    /**
     * @param  list<string>  $allowedKeywords
     */
    private static function validate(string $sql, array $allowedKeywords, string $fieldLabel): ?string
    {
        $normalized = self::stripLeadingComments(trim($sql));

        // Empty is allowed through — the query fields are nullable and their
        // presence is gated elsewhere (the importer only runs a query if set).
        if ($normalized === '') {
            return null;
        }

        // Reject statement stacking: a single optional trailing semicolon is fine.
        if (str_contains(rtrim(rtrim($normalized), ';'), ';')) {
            return "The {$fieldLabel} must be a single SQL statement (remove the extra \";\").";
        }

        $keyword = self::leadingKeyword($normalized);
        $allowed = implode(' or ', $allowedKeywords);

        if ($keyword === null || ! in_array($keyword, $allowedKeywords, true)) {
            return "The {$fieldLabel} must be a single {$allowed} statement — other statement types (DELETE, DROP, TRUNCATE, ALTER, …) are not allowed.";
        }

        return null;
    }

    private static function leadingKeyword(string $sql): ?string
    {
        if (preg_match('/^\s*([a-zA-Z]+)/', $sql, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    /**
     * Strip leading SQL comments (-- line, # line, block) so a comment cannot
     * hide the real leading statement keyword.
     */
    private static function stripLeadingComments(string $sql): string
    {
        do {
            $before = $sql;
            $sql = ltrim($sql);
            $sql = preg_replace('/^--[^\r\n]*/', '', $sql, 1) ?? $sql;
            $sql = preg_replace('/^#[^\r\n]*/', '', $sql, 1) ?? $sql;
            $sql = preg_replace('#^/\*.*?\*/#s', '', $sql, 1) ?? $sql;
        } while ($sql !== $before);

        return ltrim($sql);
    }
}
