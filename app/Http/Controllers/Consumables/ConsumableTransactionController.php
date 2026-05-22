<?php

namespace App\Http\Controllers\Consumables;

use App\Http\Controllers\Controller;
use App\Models\Consumable;
use App\Models\ConsumableTransaction;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use League\Csv\EscapeFormula;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * After-the-fact maintenance of GL transactions (journal-transfer lines).
 *
 * Transactions are recorded automatically at checkout, but the GL code is
 * only as good as the printer record at that moment — Carlos's "potential
 * mess-ups by people on the order side". This controller lets an admin
 * correct a transaction's GL (or quantity / cost / date), move it through
 * its lifecycle, or void it outright.
 */
class ConsumableTransactionController extends Controller
{
    /**
     * Show the form to create a transaction by hand — the "produce a GL
     * after the fact" path for a checkout that recorded none (the toggle
     * is opt-in, so most checkouts don't).
     */
    public function create(Consumable $consumable): View
    {
        $this->authorize('update', $consumable);

        return view('consumables.transactions.create', [
            'consumable' => $consumable,
            'compatibleModelIds' => $consumable->compatibleModels->pluck('id')->all(),
        ]);
    }

    /**
     * Persist a hand-created transaction. total_cost is computed from
     * quantity × unit cost; fiscal year from the transaction date.
     */
    public function store(Request $request, Consumable $consumable): RedirectResponse
    {
        $this->authorize('update', $consumable);

        $validated = $request->validate([
            'asset_id' => 'required|integer|exists:assets,id',
            'gl_code' => 'nullable|string|max:191',
            'transaction_date' => 'required|date',
            'quantity' => 'required|integer|min:1',
            'unit_cost' => 'nullable|numeric|min:0',
            'status' => 'required|in:'.implode(',', [
                ConsumableTransaction::STATUS_DRAFT,
                ConsumableTransaction::STATUS_POSTED,
                ConsumableTransaction::STATUS_TRANSFERRED,
            ]),
            'notes' => 'nullable|string|max:65535',
        ]);

        $unitCost = $validated['unit_cost'] !== null ? (float) $validated['unit_cost'] : null;

        ConsumableTransaction::create([
            'consumable_id' => $consumable->id,
            'asset_id' => $validated['asset_id'],
            'gl_code' => $validated['gl_code'] ?: null,
            'quantity' => $validated['quantity'],
            'unit_cost' => $unitCost,
            'total_cost' => $unitCost !== null ? $unitCost * $validated['quantity'] : null,
            'transaction_date' => $validated['transaction_date'],
            'fiscal_year' => ConsumableTransaction::fiscalYearFor($validated['transaction_date']),
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? null,
            'created_by' => auth()->id(),
        ]);

        return redirect()->to(route('consumables.show', $consumable->id).'#gl-transactions')
            ->with('success', trans('admin/consumables/message.transaction.create_success'));
    }

    /**
     * Show the edit form for a single transaction.
     */
    public function edit(Consumable $consumable, ConsumableTransaction $transaction): View|RedirectResponse
    {
        $this->authorize('update', $consumable);

        if ($transaction->consumable_id !== $consumable->id) {
            return redirect()->route('consumables.show', $consumable->id)
                ->with('error', trans('admin/consumables/message.transaction.does_not_exist'));
        }

        return view('consumables.transactions.edit', compact('consumable', 'transaction'));
    }

    /**
     * Persist an edited transaction. total_cost is always recomputed from
     * quantity × unit_cost so it cannot drift from the line it represents.
     */
    public function update(Request $request, Consumable $consumable, ConsumableTransaction $transaction): RedirectResponse
    {
        $this->authorize('update', $consumable);

        if ($transaction->consumable_id !== $consumable->id) {
            return redirect()->route('consumables.show', $consumable->id)
                ->with('error', trans('admin/consumables/message.transaction.does_not_exist'));
        }

        $validated = $request->validate([
            'gl_code' => 'nullable|string|max:191',
            'transaction_date' => 'required|date',
            'quantity' => 'required|integer|min:1',
            'unit_cost' => 'nullable|numeric|min:0',
            'status' => 'required|in:'.implode(',', [
                ConsumableTransaction::STATUS_DRAFT,
                ConsumableTransaction::STATUS_POSTED,
                ConsumableTransaction::STATUS_TRANSFERRED,
            ]),
            'notes' => 'nullable|string|max:65535',
        ]);

        $unitCost = $validated['unit_cost'] !== null ? (float) $validated['unit_cost'] : null;

        $transaction->fill([
            'gl_code' => $validated['gl_code'] ?: null,
            'transaction_date' => $validated['transaction_date'],
            'quantity' => $validated['quantity'],
            'unit_cost' => $unitCost,
            'total_cost' => $unitCost !== null ? $unitCost * $validated['quantity'] : null,
            'fiscal_year' => ConsumableTransaction::fiscalYearFor($validated['transaction_date']),
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? null,
        ]);
        $transaction->save();

        return redirect()->to(route('consumables.show', $consumable->id).'#gl-transactions')
            ->with('success', trans('admin/consumables/message.transaction.update_success'));
    }

    /**
     * Void a transaction — a soft delete, so it drops out of the ledger
     * and the GL Journal Transfer report but stays recoverable for audit.
     */
    public function destroy(Consumable $consumable, ConsumableTransaction $transaction): RedirectResponse
    {
        $this->authorize('update', $consumable);

        if ($transaction->consumable_id === $consumable->id) {
            $transaction->delete();
        }

        return redirect()->to(route('consumables.show', $consumable->id).'#gl-transactions')
            ->with('success', trans('admin/consumables/message.transaction.void_success'));
    }

    /**
     * Export the consumable's transactions. `?format=csv` streams a CSV;
     * otherwise a print-ready report page is returned (the browser's
     * print-to-PDF turns it into the document Finance gets).
     */
    public function export(Consumable $consumable, Request $request): View|StreamedResponse
    {
        $this->authorize('view', $consumable);

        $transactions = $consumable->transactions()->with('asset')->get();

        if ($request->query('format') === 'csv') {
            return $this->streamCsv($consumable, $transactions);
        }

        return view('consumables.transactions.report', [
            'consumable' => $consumable,
            'transactions' => $transactions,
            'total' => $transactions->sum(fn ($txn) => (float) $txn->total_cost),
        ]);
    }

    /**
     * Stream the transactions as a CSV with a UTF-8 BOM, formula escaping,
     * and a trailing total row.
     */
    private function streamCsv(Consumable $consumable, $transactions): StreamedResponse
    {
        $columns = [
            trans('admin/consumables/general.gl_txn_date'),
            trans('admin/consumables/general.gl_txn_printer'),
            trans('admin/consumables/general.gl_txn_code'),
            trans('admin/consumables/general.gl_txn_qty'),
            trans('admin/consumables/general.gl_txn_unit_cost'),
            trans('admin/consumables/general.gl_txn_total'),
            trans('admin/consumables/general.gl_txn_fiscal_year'),
            trans('admin/consumables/general.gl_txn_status'),
        ];

        return new StreamedResponse(function () use ($columns, $transactions) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            $formatter = new EscapeFormula('`');

            fputcsv($handle, $columns);

            $total = 0.0;
            foreach ($transactions as $txn) {
                $total += (float) $txn->total_cost;
                fputcsv($handle, $formatter->escapeRecord([
                    optional($txn->transaction_date)->format('Y-m-d'),
                    $txn->asset?->present()->name() ?? '',
                    (string) $txn->gl_code,
                    (string) $txn->quantity,
                    $txn->unit_cost,
                    $txn->total_cost,
                    (string) $txn->fiscal_year,
                    ucfirst((string) $txn->status),
                ]));
            }

            fputcsv($handle, $formatter->escapeRecord(['', '', '', '', trans('admin/orders/general.total'), $total, '', '']));
            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="transactions-'.Str::slug($consumable->name).'-'.date('Y-m-d').'.csv"',
        ]);
    }
}
