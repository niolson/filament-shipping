<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Regenerate the USPS IMpb Service Type Code table from USPS's own published
 * appendix.
 *
 * The table is committed as JSON under `resources/data/service-inference/` so the
 * app has no runtime dependency on USPS's site and so a change to it shows up as
 * a reviewable diff. This command exists so that table is never hand-transcribed:
 * download the current spreadsheet from
 * https://postalpro.usps.com/IMPB_Service_Type_Codes and point this at it.
 *
 * The ruleset version is USPS's own effective date rather than a number we
 * invent, so two inferences are comparable by asking which source they used.
 */
class BuildServiceInferenceRuleset extends Command
{
    protected $signature = 'app:build-service-inference-ruleset
                            {path : Path to the USPS "Service Type Codes Appendix I" .xlsx}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Rebuild the USPS IMpb Service Type Code table from USPS\'s published appendix';

    /**
     * Products the table is allowed to name.
     *
     * A description that does not normalize onto one of these records a null
     * product, which the inference ladder treats as inconclusive. Four rows in
     * the June 2026 appendix land here — Periodical and Saturation variants, and
     * a return-receipt row that is an extra service rather than a parcel product.
     * Falling through on those is deliberate: guessing a product for a row USPS
     * writes irregularly is exactly the confident-wrong-answer this ladder exists
     * to avoid.
     *
     * @var list<string>
     */
    private const PRODUCTS = [
        'USPS Ground Advantage Return',
        'USPS Ground Advantage',
        'Priority Mail Express Return',
        'Priority Mail Express',
        'Priority Mail Return',
        'Priority Mail',
        'First-Class Mail',
        'Parcel Select',
        'Bound Printed Matter',
        'USPS Marketing Mail Parcels',
        'USPS Marketing Mail',
        'Media Mail',
        'Library Mail',
    ];

    public function handle(): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        [$effectiveDate, $rows] = $this->readAppendix($path);

        if ($rows === []) {
            $this->error('No service type code rows found. Is this the STC appendix?');

            return self::FAILURE;
        }

        $codes = [];
        $inconclusive = [];

        foreach ($rows as $row) {
            $product = $this->normalizeProduct($row['description']);

            $codes[$row['stc']] = [
                'product' => $product,
                'description' => $row['description'],
                'mail_class' => $row['mail_class'],
                'banner' => $row['banner'],
                'extra_services' => $row['extra_services'],
                'evs' => $row['evs'],
            ];

            if ($product === null) {
                $inconclusive[] = "{$row['stc']}  {$row['description']}";
            }
        }

        ksort($codes);

        $payload = [
            'source' => 'https://postalpro.usps.com/IMPB_Service_Type_Codes',
            'document' => 'Service Type Codes for Intelligent Mail Package Barcode (IMpb), Appendix I',
            'effective_date' => $effectiveDate,
            'generated_by' => 'php artisan app:build-service-inference-ruleset',
            'codes' => $codes,
        ];

        $this->line(sprintf('%d service type codes, %d without a product', count($codes), count($inconclusive)));

        foreach ($inconclusive as $line) {
            $this->line("  inconclusive  {$line}");
        }

        $target = resource_path('data/service-inference/usps-impb-stc.json');

        if ($this->option('dry-run')) {
            $this->info("Dry run; {$target} not written.");

            return self::SUCCESS;
        }

        file_put_contents($target, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->info("Wrote {$target} (effective {$effectiveDate}).");
        $this->warn('Bump "version" in resources/data/service-inference/ruleset.json if this changed anything.');

        return self::SUCCESS;
    }

    /**
     * Pull the STC rows out of the .xlsx without a spreadsheet dependency.
     *
     * @return array{0: string, 1: list<array{stc: string, description: string, mail_class: string, banner: string, extra_services: list<string>, evs: bool}>}
     */
    private function readAppendix(string $path): array
    {
        $zip = new \ZipArchive;
        $zip->open($path);

        $shared = [];

        if (($sharedXml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach (simplexml_load_string($sharedXml)->si as $si) {
                $shared[] = implode('', array_map('strval', $si->xpath('.//text()') ?: []));
            }
        }

        $effectiveDate = '';
        $rows = [];

        // The workbook's second sheet holds the list; the first is a pivot.
        foreach (simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet2.xml'))->sheetData->row as $row) {
            $cells = [];

            foreach ($row->c as $cell) {
                $column = rtrim((string) $cell['r'], '0123456789');
                $value = (string) $cell->v;
                $cells[$column] = trim((string) $cell['t']) === 's' ? ($shared[(int) $value] ?? '') : $value;
            }

            $title = trim($cells['D'] ?? '');

            if ($effectiveDate === '' && preg_match('#Effective\s+(\d{2})/(\d{2})/(\d{4})#', $title, $m)) {
                $effectiveDate = "{$m[3]}-{$m[1]}-{$m[2]}";
            }

            $stc = trim($cells['C'] ?? '');

            if (! preg_match('/^\d{3}$/', $stc)) {
                continue;
            }

            $extraServices = [];

            foreach (['G', 'H', 'I', 'J', 'K'] as $column) {
                $code = trim($cells[$column] ?? '');

                if ($code !== '') {
                    $extraServices[] = $code;
                }
            }

            $rows[] = [
                'stc' => $stc,
                'description' => trim(preg_replace('/\s+/', ' ', $title) ?? ''),
                'mail_class' => trim($cells['E'] ?? ''),
                'banner' => trim(preg_replace('/\s+/', ' ', trim($cells['F'] ?? '')) ?? ''),
                'extra_services' => $extraServices,
                'evs' => strtoupper(trim($cells['M'] ?? '')) === 'Y',
            ];
        }

        $zip->close();

        return [$effectiveDate, $rows];
    }

    /**
     * Reduce a USPS description to the product it names, or null if it names none.
     *
     * USPS writes the product before a colon and the extra services after it, but
     * not consistently — the June 2026 appendix has doubled spaces, a trademark
     * symbol on some Marketing Mail rows, "First Class Mail" beside
     * "First-Class Mail", and a handful of rows with no colon at all. Normalizing
     * onto a closed product list rather than trusting the split keeps those from
     * becoming products of their own.
     */
    private function normalizeProduct(string $description): ?string
    {
        $head = trim(preg_split('/\s*:\s*/', $description, 2)[0]);

        $head = str_replace(['™', '®'], '', $head);
        $head = trim((string) preg_replace('/\s+/', ' ', $head));
        $head = str_ireplace('First Class Mail', 'First-Class Mail', $head);

        foreach (self::PRODUCTS as $product) {
            if (strcasecmp($head, $product) === 0 || str_starts_with(strtolower($head), strtolower($product).' ')) {
                return $product;
            }
        }

        return null;
    }
}
