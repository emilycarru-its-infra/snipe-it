<?php

namespace App\Console\Commands;

use App\Models\Asset;
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

    /** Invoice number => purchase order number, learned from the reconciliation CSV. */
    private array $invoicePoMap = [];

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

            // Learn which purchase order each cited invoice belongs to.
            foreach (explode(',', (string) ($row['CDW Invoice'] ?? '')) as $invoiceNumber) {
                $invoiceNumber = trim($invoiceNumber);
                if ($invoiceNumber !== '' && ! isset($this->invoicePoMap[$invoiceNumber])) {
                    $this->invoicePoMap[$invoiceNumber] = $po;
                }
            }
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

                if (! $dryRun) {
                    $this->assignLineItemPurchaseOrders($order, $orderLines, $primaryPo, $purchaseOrders);
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
                    'purchase_order_id' => $purchaseOrders[$line['po']]->id ?? null,
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
     * Create one invoice record per distinct CDW invoice number, and link
     * each invoiced device's line item to it by serial number.
     */
    private function importInvoices(string $path, bool $dryRun): void
    {
        $this->info(($dryRun ? '[DRY RUN] ' : '').'Importing invoices from '.basename($path).'.');

        $rowsByInvoice = [];
        foreach ($this->readCsv($path) as $row) {
            $number = trim($row['Invoice #'] ?? '');
            if ($number !== '') {
                $rowsByInvoice[$number][] = $row;
            }
        }

        $posByNumber = PurchaseOrder::pluck('id', 'po_number');

        $created = 0;
        $skipped = 0;
        $itemsLinked = 0;

        foreach ($rowsByInvoice as $number => $rows) {
            $first = $rows[0];
            $orderNumber = trim($first['Order #'] ?? '');
            $order = $orderNumber !== '' ? Order::where('order_number', $orderNumber)->first() : null;

            if (! $order) {
                $skipped++;

                continue;
            }

            // The invoice's PO comes from the reconciliation; fall back to
            // the order's primary PO when the invoice was not cited there.
            $poId = $posByNumber[$this->invoicePoMap[$number] ?? ''] ?? $order->purchase_order_id;

            $invoice = OrderInvoice::where('order_id', $order->id)
                ->where('invoice_number', $number)
                ->first();

            if (! $invoice) {
                $created++;

                if (! $dryRun) {
                    $tax = $this->money($first['Invoice Sales Tax'] ?? '0');
                    $invoice = OrderInvoice::create([
                        'order_id' => $order->id,
                        'purchase_order_id' => $poId,
                        'invoice_number' => $number,
                        'invoice_date' => $this->date($first['Invoice Date'] ?? ''),
                        'subtotal' => $this->money($first['Invoice SubTotal'] ?? '0'),
                        'tax_gst' => round($tax * self::GST_SHARE, 2),
                        'tax_pst' => round($tax * (1 - self::GST_SHARE), 2),
                        'shipping' => $this->money($first['Invoice Shipping Cost'] ?? '0'),
                        'total' => $this->money($first['Invoice Total'] ?? '0'),
                    ]);
                }
            } elseif (! $dryRun && $poId && $invoice->purchase_order_id !== $poId) {
                $invoice->purchase_order_id = $poId;
                $invoice->save();
            }

            if ($dryRun || ! $invoice) {
                continue;
            }

            // Link each invoiced device's line item to this invoice by serial.
            foreach ($rows as $row) {
                $serial = trim($row['Serial #'] ?? '');
                if ($serial === '') {
                    continue;
                }

                $asset = Asset::where('serial', $serial)->first();
                if (! $asset) {
                    continue;
                }

                $item = OrderItem::where('item_type', Asset::class)
                    ->where('item_id', $asset->id)
                    ->first();

                if ($item && $item->invoice_id !== $invoice->id) {
                    $item->invoice_id = $invoice->id;
                    $item->saveQuietly();
                    $itemsLinked++;
                }
            }
        }

        $this->info("Invoices created: {$created}. Line items linked to an invoice: {$itemsLinked}. Skipped (vendor order not in Snipe): {$skipped}.");
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
     * Charge each of an order's line items to a purchase order. When the
     * order's reconciliation lines all share one PO every item takes it;
     * when they span POs each item is matched to a line by its asset model.
     */
    private function assignLineItemPurchaseOrders(Order $order, array $orderLines, string $primaryPo, array $purchaseOrders): void
    {
        if (isset($purchaseOrders[$primaryPo]) && $order->purchase_order_id !== $purchaseOrders[$primaryPo]->id) {
            $order->purchase_order_id = $purchaseOrders[$primaryPo]->id;
            $order->saveQuietly();
        }

        $distinctPos = array_values(array_unique(array_column($orderLines, 'po')));

        foreach ($order->items as $item) {
            $poNumber = count($distinctPos) === 1
                ? $distinctPos[0]
                : $this->resolveLinePurchaseOrder($item, $orderLines, $primaryPo);

            $poId = $purchaseOrders[$poNumber]->id ?? ($purchaseOrders[$primaryPo]->id ?? null);

            if ($poId && $item->purchase_order_id !== $poId) {
                $item->purchase_order_id = $poId;
                $item->saveQuietly();
            }
        }
    }

    /**
     * For an order split across purchase orders, pick the PO of the
     * reconciliation line whose description best matches a line item's
     * asset model.
     */
    private function resolveLinePurchaseOrder(OrderItem $item, array $orderLines, string $fallback): string
    {
        $model = '';
        if ($item->item_type === Asset::class && $item->item_id) {
            $asset = Asset::with('model')->find($item->item_id);
            $model = strtolower((string) ($asset?->model?->name ?? ''));
        }

        if ($model === '') {
            return $fallback;
        }

        $modelTokens = array_filter(preg_split('/\W+/', $model));
        $bestPo = $fallback;
        $bestScore = 0;

        foreach ($orderLines as $line) {
            $lineTokens = array_filter(preg_split('/\W+/', strtolower($line['description'])));
            $score = count(array_intersect($modelTokens, $lineTokens));

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPo = $line['po'];
            }
        }

        return $bestPo;
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
