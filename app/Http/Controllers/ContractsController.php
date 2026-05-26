<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Web controller for Contracts — the licenses-side analogue of the
 * Orders procurement module. TDX is the upstream source, but rows can
 * also be created or edited manually via this controller.
 */
class ContractsController extends Controller
{
    /**
     * @throws AuthorizationException
     */
    public function index(): View
    {
        $this->authorize('view', Contract::class);

        $totalCount     = Contract::count();
        $activeCount    = Contract::active()->count();
        $umbrellaCount  = Contract::umbrellas()->count();
        $expiring90     = Contract::expiringWithin(90)->count();
        $expiring30     = Contract::expiringWithin(30)->count();
        $synthesizedCount = Contract::where('is_synthesized', true)->count();

        $themes = Contract::select('theme')
            ->whereNotNull('theme')
            ->where('theme', '!=', '')
            ->selectRaw('COUNT(*) AS n')
            ->groupBy('theme')
            ->orderByDesc('n')
            ->limit(6)
            ->get();

        return view('contracts.index', compact(
            'totalCount',
            'activeCount',
            'umbrellaCount',
            'expiring90',
            'expiring30',
            'synthesizedCount',
            'themes',
        ));
    }

    public function create(): View
    {
        $this->authorize('create', Contract::class);

        return view('contracts.edit')->with('item', new Contract);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Contract::class);

        $contract = new Contract;
        $this->fillFromRequest($contract, $request);
        $contract->source = $contract->source ?: 'manual';
        $contract->created_by = auth()->id();

        if ($contract->save()) {
            return redirect()->route('contracts.index')->with('success', trans('admin/contracts/message.create.success'));
        }

        return redirect()->back()->withInput()->withErrors($contract->getErrors());
    }

    public function show(Contract $contract): View
    {
        $this->authorize('view', Contract::class);

        $contract->load(['supplier', 'parent', 'children', 'licenses', 'assets', 'serials', 'attributes', 'adminuser', 'owner']);

        return view('contracts.view', compact('contract'));
    }

    public function edit(Contract $contract): View
    {
        $this->authorize('update', Contract::class);

        return view('contracts.edit')->with('item', $contract);
    }

    public function update(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $this->fillFromRequest($contract, $request);

        if ($contract->save()) {
            return redirect()->route('contracts.show', $contract)->with('success', trans('admin/contracts/message.update.success'));
        }

        return redirect()->back()->withInput()->withErrors($contract->getErrors());
    }

    public function destroy(Contract $contract): RedirectResponse
    {
        $this->authorize('delete', Contract::class);

        $contract->delete();

        return redirect()->route('contracts.index')->with('success', trans('admin/contracts/message.delete.success'));
    }

    private function fillFromRequest(Contract $contract, Request $request): void
    {
        foreach ([
            'contract_number', 'name', 'theme', 'product', 'fiscal_year',
            'supplier_id', 'parent_contract_id', 'type', 'workflow_status',
            'start_date', 'end_date', 'total_cost', 'currency',
            'description', 'comments_review', 'gl_code',
            'requisition_number', 'voucher_number', 'service_offering',
            'ticket_url', 'schedule_number', 'notes',
        ] as $field) {
            $contract->{$field} = $request->input($field, $contract->{$field});
        }

        $contract->is_active = $request->boolean('is_active', $contract->is_active ?? true);
    }
}
