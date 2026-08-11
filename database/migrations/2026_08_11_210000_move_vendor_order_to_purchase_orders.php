<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The purchase order becomes the document an order is placed from.
 *
 * This inverts where these facts lived. They were put on `requisitions` because
 * that is where the basket is built — but a requisition is transient: it exists
 * to be keyed into Colleague and to carry the REQM until finance issues a PO,
 * and after that it is a tracking record. The purchase order is the thing that
 * authorises spending, the thing the vendor places against, and the thing every
 * budget report already sums. So the account, the quote, the send and the
 * vendor's answers belong here.
 *
 * Existing values are carried across from the requisition that resolved to each
 * purchase order rather than being retyped, so nothing already recorded is lost.
 *
 * The account column also changes shape. It held three values (`purchase`,
 * `lease`, `curriculum`) against four real accounts, which made "curriculum"
 * ambiguous between a curriculum purchase and a curriculum lease — the two are
 * invoiced by different organisations. The four are now named explicitly and
 * the old values are mapped: purchase → purchase_admin, lease → lease_admin,
 * curriculum → lease_curriculum (what the handbook has always meant by it).
 */
class MoveVendorOrderToPurchaseOrders extends Migration
{
    private const ALIASES = [
        'purchase' => 'purchase_admin',
        'lease' => 'lease_admin',
        'curriculum' => 'lease_curriculum',
    ];

    public function up()
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            foreach ([
                'funding_account' => fn () => $table->string('funding_account', 32)->nullable()->after('cost_center'),
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
            ] as $column => $add) {
                if (! Schema::hasColumn('purchase_orders', $column)) {
                    $add();
                }
            }
        });

        // Carry what the requisitions already hold. A purchase order can in
        // principle have more than one requisition resolve to it; the earliest
        // wins, since that is the one that opened the order.
        $carried = ['funding_account', 'lease_schedule', 'quote_number', 'quote_total',
            'quote_expires_at', 'quote_confirmed_at', 'vendor_sent_at', 'vendor_changes_at',
            'vendor_changes_notes', 'vendor_order_number', 'order_cc'];

        $sources = DB::table('requisitions')
            ->whereNotNull('purchase_order_id')
            ->orderBy('id')
            ->get(array_merge(['purchase_order_id'], $carried));

        $seen = [];
        foreach ($sources as $row) {
            $poId = $row->purchase_order_id;
            if (isset($seen[$poId])) {
                continue;
            }
            $seen[$poId] = true;

            $values = [];
            foreach ($carried as $column) {
                if ($row->{$column} !== null && $row->{$column} !== '') {
                    $values[$column] = $row->{$column};
                }
            }

            if (array_key_exists('funding_account', $values)) {
                $values['funding_account'] = self::ALIASES[$values['funding_account']] ?? $values['funding_account'];
            }

            if ($values !== []) {
                DB::table('purchase_orders')->where('id', $poId)->update($values);
            }
        }

        // And rename the values still sitting on the transient records, so the
        // two sides agree while the requisition pages still read them.
        foreach (self::ALIASES as $old => $new) {
            DB::table('requisitions')->where('funding_account', $old)->update(['funding_account' => $new]);
            DB::table('store_orders')->where('funding_account', $old)->update(['funding_account' => $new]);
        }

        // `grant` was offered but is not one of the four accounts and has no
        // number behind it. Cleared rather than guessed at — an order has to be
        // given a real account before it can be sent.
        DB::table('requisitions')->where('funding_account', 'grant')->update(['funding_account' => null]);
        DB::table('store_orders')->where('funding_account', 'grant')->update(['funding_account' => null]);
    }

    public function down()
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['funding_account', 'lease_schedule', 'quote_number', 'quote_total',
                'quote_expires_at', 'quote_confirmed_at', 'vendor_sent_at', 'vendor_changes_at',
                'vendor_changes_notes', 'vendor_order_number', 'order_cc']);
        });

        foreach (array_flip(self::ALIASES) as $new => $old) {
            DB::table('requisitions')->where('funding_account', $new)->update(['funding_account' => $old]);
            DB::table('store_orders')->where('funding_account', $new)->update(['funding_account' => $old]);
        }
    }
}
