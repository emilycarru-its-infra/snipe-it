<?php

namespace App\Http\Controllers;

use App\Models\LeaseDecision;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin UI for the lease decision log. Shares the 'orders' permission
 * set, since lease decisions are part of procurement management.
 */
class LeaseDecisionsController extends Controller
{
    public function index(): View
    {
        $this->authorize('view', Order::class);

        return view('lease-decisions/index');
    }

    public function create(): View
    {
        $this->authorize('create', Order::class);

        return view('lease-decisions/edit')->with('item', new LeaseDecision);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $decision = new LeaseDecision;
        $this->fillFromRequest($decision, $request);
        $decision->created_by = auth()->id();

        if ($decision->save()) {
            return redirect()->route('lease-decisions.index')->with('success', trans('admin/lease-decisions/message.create.success'));
        }

        return redirect()->back()->withInput()->withErrors($decision->getErrors());
    }

    public function edit(LeaseDecision $lease_decision): View
    {
        $this->authorize('update', Order::class);

        return view('lease-decisions/edit')->with('item', $lease_decision);
    }

    public function update(Request $request, LeaseDecision $lease_decision): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $this->fillFromRequest($lease_decision, $request);

        if ($lease_decision->save()) {
            return redirect()->route('lease-decisions.index')->with('success', trans('admin/lease-decisions/message.update.success'));
        }

        return redirect()->back()->withInput()->withErrors($lease_decision->getErrors());
    }

    public function destroy(LeaseDecision $lease_decision): RedirectResponse
    {
        $this->authorize('delete', Order::class);

        $lease_decision->delete();

        return redirect()->route('lease-decisions.index')->with('success', trans('admin/lease-decisions/message.delete.success'));
    }

    public function bulkDelete(Request $request): RedirectResponse
    {
        $this->authorize('delete', Order::class);

        $ids = $request->input('ids');

        if (is_array($ids) && count($ids) > 0) {
            foreach (LeaseDecision::whereIn('id', $ids)->get() as $decision) {
                $decision->delete();
            }
        }

        return redirect()->route('lease-decisions.index')->with('success', trans('admin/lease-decisions/message.delete.success'));
    }

    private function fillFromRequest(LeaseDecision $decision, Request $request): void
    {
        $decision->contract_reference = $request->input('contract_reference');
        $decision->decision_type = $request->input('decision_type', 'buyout');
        $decision->decision_date = $request->input('decision_date') ?: null;
        $decision->amount = $request->input('amount') ?: null;
        $decision->status = $request->input('status', 'pending');
        $decision->notes = $request->input('notes') ?: null;
    }
}
