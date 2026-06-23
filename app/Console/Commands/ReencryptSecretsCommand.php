<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ReencryptSecretsCommand extends Command
{
    protected $signature = 'app:reencrypt-secrets {--dry-run : Report what would be re-encrypted without writing}';

    protected $description = 'Re-encrypt at-rest encrypted columns with the current APP_KEY. Run after rotating APP_KEY (with the old key kept in APP_PREVIOUS_KEYS) so the previous key can be safely retired.';

    /**
     * Encrypted columns to re-encrypt.
     *
     * @var list<array{table: string, key: string, column: string, where: array<string, mixed>}>
     */
    private array $stores = [
        ['table' => 'carrier_accounts', 'key' => 'id', 'column' => 'secret_credentials', 'where' => []],
        ['table' => 'data_sources', 'key' => 'id', 'column' => 'secret_settings', 'where' => []],
        ['table' => 'settings', 'key' => 'key', 'column' => 'value', 'where' => ['encrypted' => true]],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $totalReencrypted = 0;
        $totalFailed = 0;

        foreach ($this->stores as $store) {
            ['table' => $table, 'key' => $pk, 'column' => $column, 'where' => $where] = $store;

            $query = DB::table($table)->whereNotNull($column);
            foreach ($where as $col => $val) {
                $query->where($col, $val);
            }

            $reencrypted = 0;
            $failed = 0;

            // Buffer the rows before writing — these stores are small, and reading
            // via an unbuffered cursor while updating the same table is unsafe.
            foreach ($query->select($pk, $column)->get() as $row) {
                $value = $row->{$column};
                if ($value === null || $value === '') {
                    continue;
                }

                try {
                    $plain = Crypt::decryptString($value);
                } catch (DecryptException $e) {
                    $this->warn("  {$table}.{$column} [{$row->{$pk}}]: could not decrypt — skipped ({$e->getMessage()})");
                    $failed++;

                    continue;
                }

                if (! $dryRun) {
                    DB::table($table)
                        ->where($pk, $row->{$pk})
                        ->update([$column => Crypt::encryptString($plain)]);
                }

                $reencrypted++;
            }

            $verb = $dryRun ? 'would re-encrypt' : 're-encrypted';
            $this->line("  {$table}.{$column}: {$verb} {$reencrypted}".($failed > 0 ? ", {$failed} failed" : ''));

            $totalReencrypted += $reencrypted;
            $totalFailed += $failed;
        }

        if ($totalFailed > 0) {
            $this->error("Completed with {$totalFailed} undecryptable value(s). Do NOT drop the previous APP_KEY until these are resolved.");

            return self::FAILURE;
        }

        $this->info(($dryRun ? 'Dry run: ' : '')."re-encrypted {$totalReencrypted} value(s) with the current APP_KEY.");

        return self::SUCCESS;
    }
}
