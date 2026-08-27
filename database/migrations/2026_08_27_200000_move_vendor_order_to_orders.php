<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The vendor order is its own record under the purchase order.
 *
 * A purchase order is a budget: finance issues one number for a programme of
 * spending and the vendor places order after order against it through the
 * year. The account, the quote, the send and the vendor's answers are facts
 * about one of those orders — a 16-device wave on one lease schedule — not
 * about the budget they draw on. Held on the purchase order they could only
 * describe one send, and a second wave overwrote the first.
 *
 * So they move to `orders`, which already exists as the thing under a purchase
 * order that carries lines, ships and is invoiced. Its lines gain the two part
 * numbers the vendor keys from, and the catalog row they came from, so an
 * order can be emailed as a parts list without reaching back to a requisition.
 *
 * Whatever the purchase orders already recorded is carried onto their earliest
 * order, so nothing already agreed with the vendor is lost. The purchase order
 * columns are left in place, unread; a later migration drops them once the
 * copied values have been seen to be right.
 */
class MoveVendorOrderToOrders extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach ([
                'funding_account' => fn () => $table->string('funding_account', 32)->nullable()->after('notes'),
                'lease_schedule' => fn () => $table->string('lease_schedule')->nullable()->after('funding_account'),
                'quote_number' => fn () => $table->string('quote_number')->nullable()->after('lease_schedule'),
                'quote_total' => fn () => $table->decimal('quote_total', 15, 2)->nullable()->after('quote_number'),
                'quote_expires_at' => fn () => $table->date('quote_expires_at')->nullable()->after('quote_total'),
                'quote_confirmed_at' => fn () => $table->timestamp('quote_confirmed_at')->nullable()->after('quote_expires_at'),
                'vendor_sent_at' => fn () => $table->timestamp('vendor_sent_at')->nullable()->after('quote_confirmed_at'),
                'vendor_changes_at' => fn () => $table->timestamp('vendor_changes_at')->nullable()->after('vendor_sent_at'),
                'vendor_changes_notes' => fn () => $table->text('vendor_changes_notes')->nullable()->after('vendor_changes_at'),
                'vendor_order_number' => fn () => $table->string('vendor_order_number')->nullable()->after('vendor_changes_notes'),
                'order_cc' => fn () => $table->text('order_cc')->nullable()->after('vendor_order_number'),
                'order_cc_users' => fn () => $table->string('order_cc_users')->nullable()->after('order_cc'),
            ] as $column => $add) {
                if (! Schema::hasColumn('orders', $column)) {
                    $add();
                }
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            foreach ([
                'catalog_item_id' => fn () => $table->unsignedBigInteger('catalog_item_id')->nullable()->after('replaces_asset_id'),
                'vendor_sku' => fn () => $table->string('vendor_sku')->nullable()->after('description'),
                'mfr_part_number' => fn () => $table->string('mfr_part_number')->nullable()->after('vendor_sku'),
                'unit_of_measure' => fn () => $table->string('unit_of_measure', 16)->nullable()->after('quantity'),
            ] as $column => $add) {
                if (! Schema::hasColumn('order_items', $column)) {
                    $add();
                }
            }
        });

        // What each purchase order recorded moves to its earliest real order —
        // the one promotion raised from the requisition, which carried the
        // same lines the vendor was sent.
        $columns = [
            'funding_account', 'lease_schedule', 'quote_number', 'quote_total', 'quote_expires_at',
            'quote_confirmed_at', 'vendor_sent_at', 'vendor_changes_at', 'vendor_changes_notes',
            'vendor_order_number', 'order_cc',
        ];

        $available = array_values(array_filter($columns, fn ($column) => Schema::hasColumn('purchase_orders', $column)));

        if (Schema::hasColumn('purchase_orders', 'order_cc_users')) {
            $available[] = 'order_cc_users';
        }

        if ($available !== []) {
            $purchaseOrders = DB::table('purchase_orders')
                ->select(array_merge(['id'], $available))
                ->where(function ($query) {
                    $query->whereNotNull('vendor_sent_at')->orWhereNotNull('quote_number')->orWhereNotNull('funding_account');
                })
                ->get();

            foreach ($purchaseOrders as $purchaseOrder) {
                $order = DB::table('orders')
                    ->where('purchase_order_id', $purchaseOrder->id)
                    ->where('is_planned', false)
                    ->orderBy('id')
                    ->first();

                if (! $order) {
                    continue;
                }

                $values = [];

                foreach ($available as $column) {
                    if ($purchaseOrder->{$column} !== null && $order->{$column} === null) {
                        $values[$column] = $purchaseOrder->{$column};
                    }
                }

                if ($values !== []) {
                    DB::table('orders')->where('id', $order->id)->update($values);
                }
            }
        }

        // Lines that resolve to a model take their part numbers from the
        // catalog row for that model, which is where the vendor's numbers are
        // maintained. Lines with no model — freight, a fee — stay as text.
        $catalogByModel = DB::table('catalog_items')
            ->whereNotNull('model_id')
            ->orderBy('id')
            ->get()
            ->groupBy('model_id')
            ->map(fn ($rows) => $rows->first());

        DB::table('order_items')
            ->where('item_type', 'App\\Models\\AssetModel')
            ->whereNull('vendor_sku')
            ->whereNotNull('item_id')
            ->orderBy('id')
            ->chunkById(500, function ($items) use ($catalogByModel) {
                foreach ($items as $item) {
                    $catalog = $catalogByModel->get($item->item_id);

                    if (! $catalog) {
                        continue;
                    }

                    DB::table('order_items')->where('id', $item->id)->update([
                        'catalog_item_id' => $catalog->id,
                        'vendor_sku' => $catalog->vendor_sku,
                        'mfr_part_number' => $catalog->mfr_part_number,
                        'unit_of_measure' => 'EA',
                    ]);
                }
            });
    }

    public function down()
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['catalog_item_id', 'vendor_sku', 'mfr_part_number', 'unit_of_measure']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'funding_account', 'lease_schedule', 'quote_number', 'quote_total', 'quote_expires_at',
                'quote_confirmed_at', 'vendor_sent_at', 'vendor_changes_at', 'vendor_changes_notes',
                'vendor_order_number', 'order_cc', 'order_cc_users',
            ]);
        });
    }
}
