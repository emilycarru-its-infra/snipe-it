<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

/**
 * Reads the legacy `Devices Capital <FY>` plan as CSV and creates one
 * planned `Order` (with line items) per row. This is PR 5/5 of the
 * arc that retires `~/OneDrive/.../Devices/Procurement/Current/`.
 *
 * Expected CSV columns (case-insensitive, header row required):
 *   Area              — e.g. "Admin", "Curriculum"
 *   Contract          — e.g. "ECI20220901"
 *   Qty               — integer
 *   Model             — free text description
 *   PriceWithWarranty — unit cost INCLUDING warranty (numeric)
 *   Cost              — optional row total; computed if missing
 *
 * Source workbook is `Devices Capital <FY>.xlsx`. Export the summary
 * sheet as CSV (UTF-8) and pass the path to this command. xlsx isn't
 * read directly because Snipe-IT doesn't ship PhpSpreadsheet — we use
 * `league/csv` which is already a hard dependency.
 *
 * Usage:
 *   php artisan snipeit:import-procurement-plan path/to/file.csv \
 *     --fy=FY2026-27 [--dry-run] [--prefix="2026-IMPORT-"]
 */
class ImportProcurementPlan extends Command
{
    protected $signature = 'snipeit:import-procurement-plan
                            {path : Path to a CSV with Area,Contract,Qty,Model,PriceWithWarranty,Cost columns}
                            {--fy= : Fiscal year tag to stamp on each created Order (e.g. FY2026-27)}
                            {--prefix=PLAN- : Prefix for generated order_number values; appended with row index}
                            {--dry-run : Parse the file and report what would be created without writing}';

    protected $description = 'Import a Devices Capital <FY> CSV as planned Orders for the procurement dashboard.';

    public function handle(): int
    {
        $path = $this->argument('path');
        $fy   = $this->option('fy');

        if (! $fy) {
            $this->error('--fy=FY2026-27 (or similar) is required.');
            return self::FAILURE;
        }

        if (! is_readable($path)) {
            $this->error("Cannot read file: {$path}");
            return self::FAILURE;
        }

        $reader = Reader::createFromPath($path, 'r');
        $reader->setHeaderOffset(0);

        $rows = collect($reader->getRecords())->map(function ($row) {
            $normalized = [];
            foreach ($row as $key => $value) {
                $normalized[strtolower(trim((string) $key))] = is_string($value) ? trim($value) : $value;
            }
            return $normalized;
        });

        if ($rows->isEmpty()) {
            $this->warn('No rows found.');
            return self::SUCCESS;
        }

        $prefix  = $this->option('prefix') ?: 'PLAN-';
        $dryRun  = (bool) $this->option('dry-run');
        $createdOrders = 0;
        $createdItems  = 0;
        $skipped       = 0;
        $errors        = [];

        $work = function () use ($rows, $fy, $prefix, $dryRun, &$createdOrders, &$createdItems, &$skipped, &$errors) {
            foreach ($rows as $i => $row) {
                $area   = $row['area']     ?? null;
                $model  = $row['model']    ?? null;
                $qty    = (int) ($row['qty'] ?? 0);
                $price  = (float) ($row['pricewithwarranty'] ?? $row['price w/ warranty'] ?? 0);
                $cost   = $row['cost'] !== null && $row['cost'] !== ''
                    ? (float) $row['cost']
                    : ($qty * $price);
                $contract = $row['contract'] ?? null;

                if (! $model || $qty <= 0 || $price <= 0) {
                    $skipped++;
                    $errors[] = "row ".($i + 2).": missing model/qty/price (model='{$model}', qty={$qty}, price={$price})";
                    continue;
                }

                $orderNumber = sprintf('%s%s-%03d', $prefix, $fy, $i + 1);

                if ($dryRun) {
                    $this->line(sprintf(
                        '[DRY-RUN] %s | area=%s | %d × %s @ $%.2f = $%.2f%s',
                        $orderNumber,
                        $area ?: '—',
                        $qty,
                        $model,
                        $price,
                        $cost,
                        $contract ? " | contract={$contract}" : ''
                    ));
                    continue;
                }

                $notes = $contract ? "Imported from procurement plan. Contract: {$contract}." : 'Imported from procurement plan.';

                $order = Order::create([
                    'order_number' => $orderNumber,
                    'status'       => 'ordered',
                    'is_planned'   => true,
                    'fiscal_year'  => $fy,
                    'area'         => $area,
                    'order_date'   => now()->toDateString(),
                    'notes'        => $notes,
                ]);

                OrderItem::create([
                    'order_id'     => $order->id,
                    'description'  => $model,
                    'quantity'     => $qty,
                    'unit_cost'    => $price,
                    'warranty_cost' => 0,
                ]);

                $createdOrders++;
                $createdItems++;
            }
        };

        if ($dryRun) {
            $work();
        } else {
            DB::transaction($work);
        }

        $this->info(sprintf(
            '%s — %d order(s), %d line item(s), %d row(s) skipped.',
            $dryRun ? 'DRY-RUN parsed' : 'Imported',
            $createdOrders,
            $createdItems,
            $skipped
        ));

        if (! empty($errors)) {
            $this->warn('Skips:');
            foreach ($errors as $err) {
                $this->warn('  '.$err);
            }
        }

        return self::SUCCESS;
    }
}
