<?php

namespace App\Http\Controllers\Consumables;

use App\Http\Controllers\Controller;
use App\Models\Consumable;
use App\Models\ConsumableTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

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

        return redirect()->route('consumables.show', $consumable->id)
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

        return redirect()->route('consumables.show', $consumable->id)
            ->with('success', trans('admin/consumables/message.transaction.void_success'));
    }
}
