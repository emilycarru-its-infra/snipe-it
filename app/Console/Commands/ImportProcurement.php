<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Imports the procurement backlog from finance CSV exports. Two inputs:
 *
 *  --reconciliation  The hand-reconciled PO budget CSV. Creates purchase
 *                    orders with budgets, links each existing vendor order
 *                    to its primary PO, and creates still-pending items as
 *                    planned (forecast) orders.
 *  --invoices        The raw CDW orders CSV. Creates one invoice record per
 *                    distinct CDW invoice number, linked to its vendor order.
 *
 * Idempotent: purchase orders, planned orders and invoices are matched by
 * their natural keys, so re-running only fills gaps.
 */
class ImportProcurement extends Command
{
    protected $signature = 'procurement:import
        {--reconciliation= : Path to the PO budget reconciliation CSV}
        {--invoices= : Path to the CDW orders CSV}
        {--dry-run : Report what would happen without writing anything}';

    protected $description = 'Import purchase orders, order-to-PO links, planned orders and invoices from procurement CSV exports.';

    /** GST is 5% and PST is 7% in BC, so combined tax splits 5:7. */
    private const GST_SHARE = 5 / 12;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $reconciliationPath = $this->option('reconciliation');
        $invoicesPath = $this->option('invoices');

        if (! $reconciliationPath && ! $invoicesPath) {
            $this->error('Provide --reconciliation and/or --invoices.');

            return self::FAILURE;
        }

        foreach (['reconciliation' => $reconciliationPath, 'invoices' => $invoicesPath] as $label => $path) {
            if ($path && ! is_readable($path)) {
                $this->error("Cannot read {$label} file: {$path}");

                return self::FAILURE;
            }
        }

        if ($reconciliationPath) {
            $this->importReconciliation($reconciliationPath, $dryRun);
        }

        if ($invoicesPath) {
            $this->importInvoices($invoicesPath, $dryRun);
        }

        return self::SUCCESS;
    }

    /**
     * Create purchase orders, link vendor orders to their primary PO, and
     * create still-pending items as planned orders.
     */
    private function importReconciliation(string $path, bool $dryRun): void
    {
        $this->info(($dryRun ? '[DRY RUN] ' : '').'Importing purchase orders from '.basename($path).'.');

        $poBudgets = [];
        $lines = [];

        foreach ($this->readCsv($path) as $row) {
            $po = trim($row['PO'] ?? '');

            if ($po !== '' && trim($row['PO Budget'] ?? '') !== '') {
                $poBudgets[$po] = $this->money($row['PO Budget']);
            }

            $order = trim($row['CDW Order'] ?? '');
            $qty = trim($row['Qty'] ?? '');

            // Line-item rows carry a PO, a vendor order and a numeric qty;
            // summary, buyout and credit rows do not.
            if ($po === '' || $order === '' || ! is_numeric($qty)) {
                continue;
            }

            $lines[] = [
                'po' => $po,
                'schedule' => trim($row['Schedule'] ?? ''),
                'qty' => (int) $qty,
                'description' => trim($row['Item Description'] ?? ''),
                'order' => $order,
                'status' => trim($row['Status'] ?? ''),
                'subtotal' => $this->money($row['Subtotal'] ?? '0'),
            ];
        }

        $purchaseOrders = [];
        $posCreated = 0;

        foreach ($poBudgets as $poNumber => $budget) {
            $existing = PurchaseOrder::where('po_number', $poNumber)->first();

            if ($existing) {
                $purchaseOrders[$poNumber] = $existing;

                continue;
            }

            $posCreated++;

            if ($dryRun) {
                continue;
            }

            $purchaseOrders[$poNumber] = PurchaseOrder::create([
                'po_number' => $poNumber,
                'title' => 'CDW / CSI Capital — FY2025-26',
                'fiscal_year' => 'FY2025-26',
                'budget' => $budget,
                'status' => 'open',
            ]);
        }

        $byOrder = [];
        foreach ($lines as $line) {
            $byOrder[$line['order']][] = $line;
        }

        $linked = 0;
        $plannedCreated = 0;
        $missing = [];

        foreach ($byOrder as $orderNumber => $orderLines) {
            $primaryPo = $this->primaryPo($orderLines);
            $order = Order::where('order_number', $orderNumber)->first();

            if ($order) {
                $linked++;

                if (! $dryRun && isset($purchaseOrders[$primaryPo]) && $order->purchase_order_id !== $purchaseOrders[$primaryPo]->id) {
                    $order->purchase_order_id = $purchaseOrders[$primaryPo]->id;
                    $order->saveQuietly();
                }

                continue;
            }

            // An order absent from Snipe is only created when every line is
            // still pending — a forecast, not a realised purchase.
            $allPending = collect($orderLines)->every(fn ($line) => $line['status'] === 'Pending');

            if (! $allPending) {
                $missing[] = $orderNumber;

                continue;
            }

            $plannedCreated++;

            if ($dryRun) {
                continue;
            }

            $planned = Order::create([
                'order_number' => $orderNumber,
                'status' => 'ordered',
                'is_planned' => true,
                'fiscal_year' => $this->fiscalYearForLines($orderLines),
                'purchase_order_id' => $purchaseOrders[$primaryPo]->id ?? null,
            ]);

            foreach ($orderLines as $line) {
                OrderItem::create([
                    'order_id' => $planned->id,
                    'description' => $line['description'],
                    'quantity' => $line['qty'],
                    'unit_cost' => $line['qty'] > 0 ? round($line['subtotal'] / $line['qty'], 4) : 0,
                ]);
            }
        }

        $this->info("Purchase orders created: {$posCreated}. Orders linked to a PO: {$linked}. Planned orders created: {$plannedCreated}.");

        if ($missing) {
            $this->warn('Realised orders not found in Snipe (skipped): '.implode(', ', $missing));
        }
    }

    /**
     * Create one invoice record per distinct CDW invoice number.
     */
    private function importInvoices(string $path, bool $dryRun): void
    {
        $this->info(($dryRun ? '[DRY RUN] ' : '').'Importing invoices from '.basename($path).'.');

        $invoices = [];

        foreach ($this->readCsv($path) as $row) {
            $number = trim($row['Invoice #'] ?? '');
            $orderNumber = trim($row['Order #'] ?? '');

            if ($number === '' || $orderNumber === '' || isset($invoices[$number])) {
                continue;
            }

            $subtotal = $this->money($row['Invoice SubTotal'] ?? '0');
            $tax = $this->money($row['Invoice Sales Tax'] ?? '0');

            $invoices[$number] = [
                'order' => $orderNumber,
                'date' => $this->date($row['Invoice Date'] ?? ''),
                'subtotal' => $subtotal,
                'shipping' => $this->money($row['Invoice Shipping Cost'] ?? '0'),
                'tax_gst' => round($tax * self::GST_SHARE, 2),
                'tax_pst' => round($tax * (1 - self::GST_SHARE), 2),
                'total' => $this->money($row['Invoice Total'] ?? '0'),
            ];
        }

        $created = 0;
        $skipped = 0;

        foreach ($invoices as $number => $invoice) {
            $order = Order::where('order_number', $invoice['order'])->first();

            if (! $order) {
                $skipped++;

                continue;
            }

            if (OrderInvoice::where('order_id', $order->id)->where('invoice_number', $number)->exists()) {
                continue;
            }

            $created++;

            if ($dryRun) {
                continue;
            }

            OrderInvoice::create([
                'order_id' => $order->id,
                'invoice_number' => $number,
                'invoice_date' => $invoice['date'],
                'subtotal' => $invoice['subtotal'],
                'tax_gst' => $invoice['tax_gst'],
                'tax_pst' => $invoice['tax_pst'],
                'shipping' => $invoice['shipping'],
                'total' => $invoice['total'],
            ]);
        }

        $this->info("Invoices created: {$created}. Invoices skipped (vendor order not in Snipe): {$skipped}.");
    }

    /**
     * The PO carrying the largest share of an order's line-item subtotal.
     */
    private function primaryPo(array $orderLines): string
    {
        $sums = [];
        foreach ($orderLines as $line) {
            $sums[$line['po']] = ($sums[$line['po']] ?? 0) + $line['subtotal'];
        }
        arsort($sums);

        return (string) array_key_first($sums);
    }

    /**
     * ECU's fiscal year runs April-March. Lease schedules numbered 7 and up
     * belong to FY2026-27; earlier schedules to FY2025-26.
     */
    private function fiscalYearForLines(array $orderLines): string
    {
        $maxSchedule = 0;
        foreach ($orderLines as $line) {
            if (is_numeric($line['schedule'])) {
                $maxSchedule = max($maxSchedule, (int) $line['schedule']);
            }
        }

        return $maxSchedule >= 7 ? 'FY2026-27' : 'FY2025-26';
    }

    /**
     * Read a CSV into an array of header-keyed rows, tolerating a UTF-8 BOM.
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return [];
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $header = array_map('trim', $header);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), ''));
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Parse a money cell ("$6,783.00", "1234.50", "-9,488.15") to a float.
     */
    private function money($value): float
    {
        return (float) str_replace(['$', ',', '"', ' '], '', (string) $value);
    }

    /**
     * Parse a date cell, returning null when empty or unparseable.
     */
    private function date(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }
}
