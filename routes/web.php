<?php

use App\Actions\Breadcrumbs\BuildAcceptanceBreadcrumbs;
use App\Forms\FormRegistry;
use App\Http\Controllers\AccessAuditController;
use App\Http\Controllers\Account;
use App\Http\Controllers\ActionlogController;
use App\Http\Controllers\AssetBuyoutsController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\BudgetAllocationsController;
use App\Http\Controllers\BulkCategoriesController;
use App\Http\Controllers\BulkManufacturersController;
use App\Http\Controllers\BulkSuppliersController;
use App\Http\Controllers\CategoriesController;
use App\Http\Controllers\CompaniesController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentsController;
use App\Http\Controllers\DeploymentCatalogController;
use App\Http\Controllers\DeploymentItemsController;
use App\Http\Controllers\DeploymentsController;
use App\Http\Controllers\DepreciationsController;
use App\Http\Controllers\EmailsController;
use App\Http\Controllers\ExhibitCatalogController;
use App\Http\Controllers\ExhibitEmailTemplatesController;
use App\Http\Controllers\ExhibitProjectsController;
use App\Http\Controllers\FieldGroupsController;
use App\Http\Controllers\FleetHealthReportsController;
use App\Http\Controllers\FormsController;
use App\Http\Controllers\GroupsController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LabelsController;
use App\Http\Controllers\LeaseDecisionsController;
use App\Http\Controllers\LeaseDocumentsController;
use App\Http\Controllers\LeaseSchedulesController;
use App\Http\Controllers\LicenseModelsController;
use App\Http\Controllers\ManufacturersController;
use App\Http\Controllers\ModalController;
use App\Http\Controllers\NotesController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\PrintingReportsController;
use App\Http\Controllers\ProcurementController;
use App\Http\Controllers\ProcurementReportsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseOrdersController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\ReportTemplatesController;
use App\Http\Controllers\RequisitionsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\StaffBlackoutsController;
use App\Http\Controllers\StatuslabelsController;
use App\Http\Controllers\TopSearchController;
use App\Http\Controllers\StorageProxyController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\SuppliersController;
use App\Http\Controllers\TonersController;
use App\Http\Controllers\TransactionsReportsController;
use App\Http\Controllers\UploadedFilesController;
use App\Http\Controllers\UserAgreementsController;
use App\Http\Controllers\ViewAssetsController;
use App\Livewire\Importer;
use App\Mail\CheckoutComponentMail;
use App\Models\ReportTemplate;
use Illuminate\Support\Facades\Route;
use Tabuna\Breadcrumbs\Trail;

Route::group(['middleware' => 'auth'], function () {
    /*
    * Companies
    */
    Route::resource('companies', CompaniesController::class, [
        'parameters' => ['company' => 'company_id'],
    ]);

    /*
    * Categories
    */
    Route::resource('categories', CategoriesController::class, [
        'parameters' => ['category' => 'category_id'],
    ]);

    Route::post('categories/bulk/delete', [BulkCategoriesController::class, 'destroy'])->name('categories.bulk.delete');

    /*
    * Labels
    */
    Route::get(
        'labels/{labelName}',
        [LabelsController::class, 'show']
    )->where('labelName', '.*')->name('labels.show');

    Route::get('/test-email', function () {
        $mailable = new CheckoutComponentMail;

        return $mailable->render(); // dumps HTML
    });
    /*
    * Manufacturers
    */

    Route::group(['prefix' => 'manufacturers', 'middleware' => ['auth']], function () {
        Route::post('{manufacturers_id}/restore', [ManufacturersController::class, 'restore'])->name('restore/manufacturer');
        Route::post('seed', [ManufacturersController::class, 'seed'])->name('manufacturers.seed');

        // Toner dashboard subsection re-ordering. Swaps display_order with
        // the adjacent manufacturer (alphabetical tie-break inside the same
        // order value). Used by the up/down arrows on /toners and the
        // dashboard embedded above /consumables.
        Route::post('{manufacturer}/move-up', [ManufacturersController::class, 'moveUp'])->name('manufacturers.move-up');
        Route::post('{manufacturer}/move-down', [ManufacturersController::class, 'moveDown'])->name('manufacturers.move-down');
    });

    Route::resource('manufacturers', ManufacturersController::class);

    Route::post('manufacturers/bulk/delete', [BulkManufacturersController::class, 'destroy'])->name('manufacturers.bulk.delete');

    /*
    * Suppliers
    */
    Route::resource('suppliers', SuppliersController::class);

    Route::post('suppliers/bulk/delete', [BulkSuppliersController::class, 'destroy'])->name('suppliers.bulk.delete');

    /*
    * Orders
    */
    // Before the resource: {order} would otherwise swallow "unmatched".
    Route::get('procurement/orders/unmatched', [OrdersController::class, 'unmatched'])
        ->name('orders.unmatched')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('admin/orders/general.orders'), route('orders.index'))
            ->push(trans('admin/orders/general.allocation_heading'), route('orders.unmatched')));
    Route::resource('procurement/orders', OrdersController::class);
    Route::post('procurement/orders/bulk/delete', [OrdersController::class, 'bulkDelete'])->name('orders.bulk.delete');
    Route::post('procurement/orders/allocate', [OrdersController::class, 'allocate'])->name('orders.allocate');

    // Type-ahead behind the toolbar lookup. No breadcrumb: it answers XHR,
    // it is never a page you land on.
    Route::get('search/suggest', [TopSearchController::class, 'suggest'])->name('search.suggest');

    // The end-user one-stop: tracker, lease, and everything checked out to
    // them, at a link short enough to say out loud.
    Route::get('my', [DashboardController::class, 'my'])->name('my');
    Route::post('my/assets/{asset}/request-buyout', [DashboardController::class, 'myRequestBuyout'])
        ->name('my.request-buyout');
    Route::get('procurement/orders/{order}/export', [OrdersController::class, 'export'])->name('orders.export');
    Route::post('procurement/orders/{order}/cancel', [OrdersController::class, 'cancel'])->name('orders.cancel');
    Route::post('procurement/orders/{order}/reopen', [OrdersController::class, 'reopen'])->name('orders.reopen');
    Route::post('procurement/orders/{order}/items', [OrdersController::class, 'storeItem'])->name('orders.items.store');
    Route::delete('procurement/orders/{order}/items/{item}', [OrdersController::class, 'destroyItem'])->name('orders.items.destroy');
    Route::post('procurement/orders/{order}/items/{item}/receive', [OrdersController::class, 'receiveItem'])->name('orders.items.receive');
    Route::post('procurement/orders/{order}/items/{item}/unreceive', [OrdersController::class, 'unreceiveItem'])->name('orders.items.unreceive');
    Route::post('procurement/orders/{order}/shipments', [OrdersController::class, 'storeShipment'])->name('orders.shipments.store');
    Route::put('procurement/orders/{order}/shipments/{shipment}', [OrdersController::class, 'updateShipment'])->name('orders.shipments.update');
    Route::delete('procurement/orders/{order}/shipments/{shipment}', [OrdersController::class, 'destroyShipment'])->name('orders.shipments.destroy');
    Route::post('procurement/orders/{order}/shipments/{shipment}/receive', [OrdersController::class, 'receiveShipment'])->name('orders.shipments.receive');
    Route::post('procurement/orders/{order}/invoices', [OrdersController::class, 'storeInvoice'])->name('orders.invoices.store');
    Route::put('procurement/orders/{order}/invoices/{invoice}', [OrdersController::class, 'updateInvoice'])->name('orders.invoices.update');
    Route::delete('procurement/orders/{order}/invoices/{invoice}', [OrdersController::class, 'destroyInvoice'])->name('orders.invoices.destroy');

    /*
    * Purchase Orders
    */
    // The PO builder: operational home under /purchase-orders, out of
    // the reports tree it started life in. Registered before the
    // resource so "builder" never binds as a {purchase_order}.
    Route::get('procurement/purchase-orders/builder', [RequisitionsController::class, 'builder'])
        ->name('purchase-orders.builder')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('procurement.index')
            ->push(trans('admin/purchase-orders/general.report_po_builder'), route('purchase-orders.builder')));

    Route::resource('procurement/purchase-orders', PurchaseOrdersController::class);
    Route::post('procurement/purchase-orders/bulk/delete', [PurchaseOrdersController::class, 'bulkDelete'])->name('purchase-orders.bulk.delete');

    // Placing the order, and recording what the vendor says back. On the
    // purchase order rather than the requisition because the purchase order is
    // what authorises the spending and what the vendor bills against — the
    // requisition is the transient basket that produced the number.
    Route::post('procurement/purchase-orders/{purchase_order}/send-vendor', [PurchaseOrdersController::class, 'sendVendor'])
        ->name('purchase-orders.send-vendor');
    Route::post('procurement/purchase-orders/{purchase_order}/vendor-response', [PurchaseOrdersController::class, 'vendorResponse'])
        ->name('purchase-orders.vendor-response');

    /*
    * The internal store — every authenticated user can browse and order.
    * No procurement permission on this side; the gate is on the queue.
    */
    $storeCrumb = fn (Trail $trail) => $trail->parent('home')
        ->push(trans('admin/store/general.store'), route('store.index'));

    Route::get('store', [StoreController::class, 'index'])
        ->name('store.index')
        ->breadcrumbs($storeCrumb);
    Route::post('store/orders', [StoreController::class, 'store'])
        ->name('store.orders.store');
    Route::get('store/orders', [StoreController::class, 'orders'])
        ->name('store.orders')
        ->breadcrumbs(fn (Trail $trail) => ($storeCrumb)($trail)
            ->push(trans('admin/store/general.my_orders'), route('store.orders')));
    Route::post('store/orders/{order}/cancel', [StoreController::class, 'cancel'])
        ->name('store.orders.cancel');

    /*
    * Procurement — the operational hub. The store approval queue and
    * storefront management live here; the PO builder and requisitions are
    * reachable from its landing. /reports/procurement stays reporting.
    */
    $procCrumb = fn (Trail $trail) => $trail->parent('home')
        ->push(trans('admin/store/general.procurement'), route('procurement.index'));

    Route::get('procurement/hub', [ProcurementController::class, 'index'])
        ->name('procurement.index')
        ->breadcrumbs($procCrumb);
    // "Approvals" is what this page is for, and what people call it. The
    // old /procurement/queue keeps working — it is linked from emails that
    // have already gone out, so it redirects rather than 404s.
    // Named, so route('procurement.queue') still resolves for anything not
    // yet updated. It is referenced from the top navigation, so a stale call
    // would throw on every page rather than break one link. Transitional —
    // remove once no branch in flight refers to it.
    Route::redirect('procurement/queue', 'procurement/approvals', 301)
        ->name('procurement.queue');
    Route::get('procurement/approvals', [ProcurementController::class, 'queue'])
        ->name('procurement.approvals')
        ->breadcrumbs(fn (Trail $trail) => ($procCrumb)($trail)
            ->push(trans('admin/store/general.queue'), route('procurement.approvals')));
    Route::post('procurement/approvals/{order}/decide', [ProcurementController::class, 'decide'])
        ->name('procurement.queue.decide');
    Route::post('procurement/approvals/pull', [ProcurementController::class, 'pullIntoRequisition'])
        ->name('procurement.queue.pull');
    Route::post('procurement/approvals/send-vendor', [ProcurementController::class, 'sendVendorOrders'])
        ->name('procurement.queue.send-vendor');
    Route::post('procurement/approvals/{order}/funding', [ProcurementController::class, 'setFunding'])
        ->name('procurement.queue.funding');
    Route::post('procurement/approvals/{order}/quote', [ProcurementController::class, 'recordQuote'])
        ->name('procurement.queue.quote');
    // Throwing away dead requests — declined or cancelled, one or all.
    Route::delete('procurement/approvals/{order}', [ProcurementController::class, 'destroyOrder'])
        ->name('procurement.queue.destroy');
    Route::post('procurement/approvals/clear', [ProcurementController::class, 'clearDecided'])
        ->name('procurement.queue.clear');
    Route::post('procurement/approvers', [ProcurementController::class, 'saveApprovers'])
        ->name('procurement.approvers.save');
    Route::get('procurement/store', [ProcurementController::class, 'storeAdmin'])
        ->name('procurement.store-admin')
        ->breadcrumbs(fn (Trail $trail) => ($procCrumb)($trail)
            ->push(trans('admin/store/general.store_admin'), route('procurement.store-admin')));
    Route::post('procurement/store/{item}', [ProcurementController::class, 'updateStoreItem'])
        ->name('procurement.store-admin.update');

    /*
    * Requisitions — the baskets the PO builder produces, before Colleague
    * has issued a REQM number and finance has issued a PO number. The
    * builder itself lives under the procurement reports (see below), which
    * is where the reader is when they decide what to buy.
    */
    $reqCrumb = fn (Trail $trail) => $trail->parent('home')
        ->push(trans('admin/purchase-orders/general.requisitions'), route('requisitions.index'));

    Route::get('procurement/requisitions', [RequisitionsController::class, 'index'])
        ->name('requisitions.index')
        ->breadcrumbs($reqCrumb);
    Route::post('procurement/requisitions', [RequisitionsController::class, 'store'])
        ->name('requisitions.store');
    Route::get('procurement/requisitions/{requisition}', [RequisitionsController::class, 'show'])
        ->name('requisitions.show')
        ->breadcrumbs(fn (Trail $trail, $requisition) => ($reqCrumb)($trail)
            ->push($requisition->display_name, route('requisitions.show', $requisition)));
    Route::patch('procurement/requisitions/{requisition}', [RequisitionsController::class, 'update'])
        ->name('requisitions.update');
    // The crossing into the budget ledger: REQM becomes PO, PDF in hand.
    Route::post('procurement/requisitions/{requisition}/promote', [RequisitionsController::class, 'promote'])
        ->name('requisitions.promote');
    Route::delete('procurement/requisitions/{requisition}', [RequisitionsController::class, 'destroy'])
        ->name('requisitions.destroy');
    Route::get('procurement/requisitions/{requisition}/print', [RequisitionsController::class, 'print'])
        ->name('requisitions.print');
    Route::get('procurement/requisitions/{requisition}/export', [RequisitionsController::class, 'export'])
        ->name('requisitions.export');

    /*
    * Lease Decisions
    */
    $ldCrumb = fn (Trail $trail) => $trail->parent('home')
        ->push(trans('admin/lease-decisions/general.lease_decisions'), route('lease-decisions.index'));

    Route::get('procurement/lease-decisions', [LeaseDecisionsController::class, 'index'])
        ->name('lease-decisions.index')
        ->breadcrumbs($ldCrumb);
    Route::get('procurement/lease-decisions/create', [LeaseDecisionsController::class, 'create'])
        ->name('lease-decisions.create')
        ->breadcrumbs(fn (Trail $trail) => ($ldCrumb)($trail)
            ->push(trans('admin/lease-decisions/general.create'), route('lease-decisions.create')));
    Route::get('procurement/lease-decisions/{lease_decision}/edit', [LeaseDecisionsController::class, 'edit'])
        ->name('lease-decisions.edit')
        ->breadcrumbs(fn (Trail $trail, $lease_decision) => ($ldCrumb)($trail)
            ->push(trans('admin/lease-decisions/general.update'), route('lease-decisions.edit', $lease_decision)));

    Route::resource('procurement/lease-decisions', LeaseDecisionsController::class)
        ->except(['show', 'index', 'create', 'edit']);
    Route::post('procurement/lease-decisions/bulk/delete', [LeaseDecisionsController::class, 'bulkDelete'])->name('lease-decisions.bulk.delete');

    /*
    * User Agreement Program agreements
    */
    Route::post('user-agreements/pregen-pdfs', [UserAgreementsController::class, 'pregenAll'])
        ->name('user-agreements.pregen-pdfs');

    // GET routes need breadcrumbs (the rest are POST/PATCH/DELETE that
    // redirect). Chain each one off the ledger so the trail is
    // Home > Reports > Procurement Reports > Agreements > <this page>.
    $uaCrumb = fn (Trail $trail) => $trail->parent('reports.procurement.user-agreement-ledger');

    Route::get('user-agreements/create', [UserAgreementsController::class, 'create'])
        ->name('user-agreements.create')
        ->breadcrumbs(fn (Trail $trail) => ($uaCrumb)($trail)
            ->push(trans('admin/user-agreements/general.create'), route('user-agreements.create')));
    Route::get('user-agreements/{userAgreement}/edit', [UserAgreementsController::class, 'edit'])
        ->name('user-agreements.edit')
        ->breadcrumbs(fn (Trail $trail, $userAgreement) => ($uaCrumb)($trail)
            ->push(trans('admin/user-agreements/general.agreement').' #'.$userAgreement->id, route('user-agreements.show', $userAgreement))
            ->push(trans('admin/user-agreements/general.update'), route('user-agreements.edit', $userAgreement)));
    Route::get('user-agreements/{userAgreement}', [UserAgreementsController::class, 'show'])
        ->name('user-agreements.show')
        ->breadcrumbs(fn (Trail $trail, $userAgreement) => ($uaCrumb)($trail)
            ->push(trans('admin/user-agreements/general.agreement').' #'.$userAgreement->id, route('user-agreements.show', $userAgreement)));

    Route::resource('user-agreements', UserAgreementsController::class)
        ->except(['create', 'edit', 'show']);
    Route::post('user-agreements/{userAgreement}/send-for-signature', [UserAgreementsController::class, 'sendForSignature'])
        ->name('user-agreements.send-for-signature');
    Route::post('user-agreements/{userAgreement}/pregen-pdf', [UserAgreementsController::class, 'pregen'])
        ->name('user-agreements.pregen-pdf');
    Route::post('user-agreements/{userAgreement}/regenerate', [UserAgreementsController::class, 'regenerate'])
        ->name('user-agreements.regenerate');
    Route::post('user-agreements/{userAgreement}/cancel', [UserAgreementsController::class, 'cancel'])
        ->name('user-agreements.cancel');
    Route::post('user-agreements/{userAgreement}/send-to-payroll', [UserAgreementsController::class, 'sendToPayroll'])
        ->name('user-agreements.send-to-payroll');
    Route::get('user-agreements/{userAgreement}/pdf', [UserAgreementsController::class, 'downloadPdf'])
        ->name('user-agreements.pdf');

    /*
    * Exhibit projects — Grad Show / exhibit equipment tracking board.
    * GET routes carry breadcrumbs chained off the /deployments/exhibits board.
    */
    $exhibitCrumb = fn (Trail $trail) => $trail->parent('deployments.exhibits');

    Route::get('exhibit-projects/create', [ExhibitProjectsController::class, 'create'])
        ->name('exhibit-projects.create')
        ->breadcrumbs(fn (Trail $trail) => ($exhibitCrumb)($trail)
            ->push(trans('admin/exhibit-projects/general.create'), route('exhibit-projects.create')));
    Route::get('exhibit-projects/{exhibitProject}/edit', [ExhibitProjectsController::class, 'edit'])
        ->name('exhibit-projects.edit')
        ->breadcrumbs(fn (Trail $trail, $exhibitProject) => ($exhibitCrumb)($trail)
            ->push(trans('admin/exhibit-projects/general.update'), route('exhibit-projects.edit', $exhibitProject)));
    Route::get('exhibit-projects/{exhibitProject}', [ExhibitProjectsController::class, 'show'])
        ->name('exhibit-projects.show')
        ->breadcrumbs(fn (Trail $trail, $exhibitProject) => ($exhibitCrumb)($trail)
            ->push('#'.$exhibitProject->id, route('exhibit-projects.show', $exhibitProject)));

    Route::resource('exhibit-projects', ExhibitProjectsController::class)
        ->except(['index', 'create', 'edit', 'show']);
    Route::post('exhibit-projects/{exhibitProject}/email', [ExhibitProjectsController::class, 'sendEmail'])
        ->name('exhibit-projects.email');

    // Editable, DB-backed copy for the student emails.
    Route::get('exhibit-email-templates', [ExhibitEmailTemplatesController::class, 'index'])
        ->name('exhibit-email-templates.index')
        ->breadcrumbs(fn (Trail $trail) => ($exhibitCrumb)($trail)
            ->push(trans('admin/exhibit-projects/general.email_templates'), route('exhibit-email-templates.index')));
    Route::get('exhibit-email-templates/{exhibitEmailTemplate}/edit', [ExhibitEmailTemplatesController::class, 'edit'])
        ->name('exhibit-email-templates.edit')
        ->breadcrumbs(fn (Trail $trail, $exhibitEmailTemplate) => ($exhibitCrumb)($trail)
            ->push(trans('admin/exhibit-projects/general.email_templates'), route('exhibit-email-templates.index'))
            ->push($exhibitEmailTemplate->name, route('exhibit-email-templates.edit', $exhibitEmailTemplate)));
    Route::put('exhibit-email-templates/{exhibitEmailTemplate}', [ExhibitEmailTemplatesController::class, 'update'])
        ->name('exhibit-email-templates.update');

    // CSV backfill upload (historical years). Files parsed in-memory; PII
    // never persisted to the repo.
    Route::get('exhibit-projects/import/form', [ExhibitProjectsController::class, 'importForm'])
        ->name('exhibit-projects.import-form')
        ->breadcrumbs(fn (Trail $trail) => ($exhibitCrumb)($trail)
            ->push(trans('admin/exhibit-projects/general.import_title'), route('exhibit-projects.import-form')));
    Route::post('exhibit-projects/import', [ExhibitProjectsController::class, 'import'])
        ->name('exhibit-projects.import');

    // Editable taxonomy catalogs (exhibits / project-types / statuses).
    Route::get('exhibit-config/{catalog}', [ExhibitCatalogController::class, 'index'])
        ->name('exhibit-config.index')
        ->breadcrumbs(fn (Trail $trail, $catalog) => ($exhibitCrumb)($trail)
            ->push(trans('admin/exhibit-projects/general.configure'), route('exhibit-config.index', $catalog)));
    Route::get('exhibit-config/{catalog}/create', [ExhibitCatalogController::class, 'create'])
        ->name('exhibit-config.create')
        ->breadcrumbs(fn (Trail $trail, $catalog) => ($exhibitCrumb)($trail)
            ->push(trans('admin/exhibit-projects/general.configure'), route('exhibit-config.index', $catalog)));
    Route::post('exhibit-config/{catalog}', [ExhibitCatalogController::class, 'store'])
        ->name('exhibit-config.store');
    Route::get('exhibit-config/{catalog}/{id}/edit', [ExhibitCatalogController::class, 'edit'])
        ->name('exhibit-config.edit')
        ->breadcrumbs(fn (Trail $trail, $catalog, $id) => ($exhibitCrumb)($trail)
            ->push(trans('admin/exhibit-projects/general.configure'), route('exhibit-config.index', $catalog)));
    Route::put('exhibit-config/{catalog}/{id}', [ExhibitCatalogController::class, 'update'])
        ->name('exhibit-config.update');
    Route::delete('exhibit-config/{catalog}/{id}', [ExhibitCatalogController::class, 'destroy'])
        ->name('exhibit-config.destroy');

    /*
    * Deployments — operational equipment-refresh planning workspace.
    * GET routes carry breadcrumbs chained off the /reports/deployments board.
    */
    $deploymentCrumb = fn (Trail $trail) => $trail->parent('reports.deployments');

    Route::get('deployments/planning', [DeploymentsController::class, 'forecast'])
        ->name('deployments.planning')
        ->breadcrumbs(fn (Trail $trail) => ($deploymentCrumb)($trail)
            ->push(trans('admin/deployments/general.forecast'), route('deployments.planning')));
    Route::post('deployments/planning/add', [DeploymentsController::class, 'addFromForecast'])
        ->name('deployments.planning.add');
    Route::post('deployments/planning/defer', [DeploymentsController::class, 'deferFromPlanning'])
        ->name('deployments.planning.defer');
    Route::post('deployments/planning/buyout', [DeploymentsController::class, 'buyoutFromPlanning'])
        ->name('deployments.planning.buyout');

    // Forecast became Planning — the one place for all of it.
    Route::get('deployments/forecast', function () {
        $query = request()->getQueryString();

        return redirect('/deployments/planning'.($query ? '?'.$query : ''), 301);
    });

    Route::get('deployments/storage', [DeploymentsController::class, 'storage'])
        ->name('deployments.storage')
        ->middleware('can:view,App\Models\Order')
        ->breadcrumbs(fn (Trail $trail) => ($deploymentCrumb)($trail)
            ->push(trans('admin/deployments/general.storage_title'), route('deployments.storage')));

    // Which rooms are storage and how much they hold, plus where each wave
    // stages — configuration, so it is edited from the page it governs.
    Route::post('deployments/storage/config', [DeploymentsController::class, 'storageConfig'])
        ->name('deployments.storage.config')
        ->middleware('can:view,App\Models\Order');

    // Staff availability blackouts (vacation / OOO) — manual CRUD. The
    // list itself is a configuration table on the Waves page; the index
    // route survives as a redirect there.
    Route::get('deployments/blackouts', [StaffBlackoutsController::class, 'index'])
        ->name('deployments.blackouts.index');
    Route::get('deployments/blackouts/create', [StaffBlackoutsController::class, 'create'])
        ->name('deployments.blackouts.create')
        ->breadcrumbs(fn (Trail $trail) => ($deploymentCrumb)($trail)
            ->push(trans('admin/deployments/general.blackouts_title'), route('deployment-waves.index'))
            ->push(trans('admin/deployments/general.blackout_create'), route('deployments.blackouts.create')));
    Route::post('deployments/blackouts', [StaffBlackoutsController::class, 'store'])
        ->name('deployments.blackouts.store');
    Route::get('deployments/blackouts/{blackout}/edit', [StaffBlackoutsController::class, 'edit'])
        ->name('deployments.blackouts.edit')
        ->breadcrumbs(fn (Trail $trail, $blackout) => ($deploymentCrumb)($trail)
            ->push(trans('admin/deployments/general.blackouts_title'), route('deployment-waves.index'))
            ->push(trans('admin/deployments/general.blackout_update'), route('deployments.blackouts.edit', $blackout)));
    Route::put('deployments/blackouts/{blackout}', [StaffBlackoutsController::class, 'update'])
        ->name('deployments.blackouts.update');
    Route::delete('deployments/blackouts/{blackout}', [StaffBlackoutsController::class, 'destroy'])
        ->name('deployments.blackouts.destroy');

    Route::get('deployments/waves', [DeploymentsController::class, 'waves'])
        ->name('deployment-waves.index')
        ->breadcrumbs(fn (Trail $trail) => ($deploymentCrumb)($trail)
            ->push(trans('admin/deployments/general.waves_title'), route('deployment-waves.index')));
    Route::get('deployments/decommissioning', [DeploymentsController::class, 'decommissioning'])
        ->name('deployments.decommissioning')
        ->breadcrumbs(fn (Trail $trail) => ($deploymentCrumb)($trail)
            ->push(trans('admin/deployments/general.decom_nav'), route('deployments.decommissioning')));
    Route::get('deployments/exhibits', [ExhibitProjectsController::class, 'report'])
        ->name('deployments.exhibits')
        ->middleware('can:view,App\Models\Order')
        ->breadcrumbs(fn (Trail $trail) => ($deploymentCrumb)($trail)
            ->push(trans('admin/exhibit-projects/general.dashboard_title'), route('deployments.exhibits')));
    Route::post('deployments/exhibits/compose', [ExhibitProjectsController::class, 'compose'])
        ->name('exhibit-projects.compose');
    Route::get('deployments/waves/create', [DeploymentsController::class, 'create'])
        ->name('deployment-waves.create')
        ->breadcrumbs(fn (Trail $trail) => ($deploymentCrumb)($trail)
            ->push(trans('admin/deployments/general.create'), route('deployment-waves.create')));
    Route::get('deployments/waves/{deploymentWave}', [DeploymentsController::class, 'show'])
        ->name('deployment-waves.show')
        ->breadcrumbs(fn (Trail $trail, $deploymentWave) => ($deploymentCrumb)($trail)
            ->push($deploymentWave->name, route('deployment-waves.show', $deploymentWave)));

    // Names stay `deployment-waves.*` so every route() call and breadcrumb in the
    // app is untouched by the move; only the path changed.
    Route::resource('deployments/waves', DeploymentsController::class)
        ->parameters(['waves' => 'deploymentWave'])
        ->names([
            'store' => 'deployment-waves.store',
            'update' => 'deployment-waves.update',
            'destroy' => 'deployment-waves.destroy',
        ])
        ->except(['index', 'create', 'edit', 'show']);
    Route::get('deployments/waves/{deploymentWave}/export', [DeploymentsController::class, 'exportWave'])
        ->name('deployment-waves.export');

    // Announcing a wave to the people in it — and thereby starting it.
    Route::post('deployments/waves/{deploymentWave}/announce', [DeploymentsController::class, 'announce'])
        ->name('deployment-waves.announce');

    // Allocating equipment the university already owns to a wave — the
    // relocation/exhibit shape, where nothing is purchased.
    Route::post('deployments/waves/{deploymentWave}/items/existing', [DeploymentItemsController::class, 'storeExisting'])
        ->name('deployment-items.store-existing');

    // Per-device item rows on a wave board.
    Route::post('deployment-items/bulk-stage', [DeploymentItemsController::class, 'bulkStage'])
        ->name('deployment-items.bulk-stage');
    Route::post('deployment-items/bulk-group', [DeploymentItemsController::class, 'bulkGroup'])
        ->name('deployment-items.bulk-group');
    Route::post('deployments/decommission/location', [DeploymentsController::class, 'setHoldingLocation'])
        ->name('deployments.decommission.location');

    // A device leaving by purchase rather than pickup: the quote, the split,
    // the decision, the invoice, the payment. The per-device page is the
    // link handed around the lessor thread.
    Route::get('deployments/decommissioning/{assetTag}', [AssetBuyoutsController::class, 'show'])
        ->name('buyouts.show')
        ->breadcrumbs(fn (Trail $trail, $assetTag) => ($deploymentCrumb)($trail)
            ->push(trans('admin/deployments/general.decom_nav'), route('deployments.decommissioning'))
            ->push($assetTag, route('buyouts.show', $assetTag)));
    Route::post('buyouts/open', [AssetBuyoutsController::class, 'open'])
        ->name('buyouts.open');
    Route::post('buyouts/{buyout}/quote', [AssetBuyoutsController::class, 'quote'])
        ->name('buyouts.quote');
    Route::post('buyouts/{buyout}/status', [AssetBuyoutsController::class, 'transition'])
        ->name('buyouts.transition');
    Route::patch('buyouts/{buyout}', [AssetBuyoutsController::class, 'update'])
        ->name('buyouts.update');
    Route::post('deployment-items', [DeploymentItemsController::class, 'store'])
        ->name('deployment-items.store');
    Route::post('deployment-items/{deploymentItem}/stage', [DeploymentItemsController::class, 'updateStage'])
        ->name('deployment-items.stage');
    Route::put('deployment-items/{deploymentItem}', [DeploymentItemsController::class, 'update'])
        ->name('deployment-items.update');
    Route::delete('deployment-items/{deploymentItem}', [DeploymentItemsController::class, 'destroy'])
        ->name('deployment-items.destroy');

    // Editable catalogs (wave types / per-device stages).
    Route::get('deployment-config/{catalog}', [DeploymentCatalogController::class, 'index'])
        ->name('deployment-config.index')
        ->breadcrumbs(fn (Trail $trail, $catalog) => ($deploymentCrumb)($trail)
            ->push(trans('admin/deployments/general.configure'), route('deployment-config.index', $catalog)));
    Route::get('deployment-config/{catalog}/create', [DeploymentCatalogController::class, 'create'])
        ->name('deployment-config.create')
        ->breadcrumbs(fn (Trail $trail, $catalog) => ($deploymentCrumb)($trail)
            ->push(trans('admin/deployments/general.configure'), route('deployment-config.index', $catalog)));
    Route::post('deployment-config/{catalog}', [DeploymentCatalogController::class, 'store'])
        ->name('deployment-config.store');
    Route::get('deployment-config/{catalog}/{id}/edit', [DeploymentCatalogController::class, 'edit'])
        ->name('deployment-config.edit')
        ->breadcrumbs(fn (Trail $trail, $catalog, $id) => ($deploymentCrumb)($trail)
            ->push(trans('admin/deployments/general.configure'), route('deployment-config.index', $catalog)));
    Route::put('deployment-config/{catalog}/{id}', [DeploymentCatalogController::class, 'update'])
        ->name('deployment-config.update');
    Route::delete('deployment-config/{catalog}/{id}', [DeploymentCatalogController::class, 'destroy'])
        ->name('deployment-config.destroy');

    /*
    * Field Groups — editable taxonomy that organizes custom fields into
    * grouped boxes on the asset detail view.
    */
    Route::get('field-groups', [FieldGroupsController::class, 'index'])
        ->name('field-groups.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->push(trans('admin/custom_fields/general.field_groups'), route('field-groups.index')));
    Route::get('field-groups/create', [FieldGroupsController::class, 'create'])
        ->name('field-groups.create')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('field-groups.index')
            ->push(trans('button.add'), route('field-groups.create')));
    Route::post('field-groups', [FieldGroupsController::class, 'store'])
        ->name('field-groups.store');
    Route::get('field-groups/{fieldGroup}/edit', [FieldGroupsController::class, 'edit'])
        ->name('field-groups.edit')
        ->breadcrumbs(fn (Trail $trail, $fieldGroup) => $trail->parent('field-groups.index')
            ->push($fieldGroup->name, route('field-groups.edit', $fieldGroup)));
    Route::put('field-groups/{fieldGroup}', [FieldGroupsController::class, 'update'])
        ->name('field-groups.update');
    Route::delete('field-groups/{fieldGroup}', [FieldGroupsController::class, 'destroy'])
        ->name('field-groups.destroy');
    Route::post('field-groups/assign/{field}', [FieldGroupsController::class, 'assign'])
        ->name('field-groups.assign');

    /*
    * Lease Schedules
    */
    // Lease document intake: drop a lessor document, preview the parsed
    // fields, then commit through the shared intake service.
    Route::post('lease-documents/parse', [LeaseDocumentsController::class, 'parse'])
        ->name('lease-documents.parse');
    Route::post('lease-documents/commit', [LeaseDocumentsController::class, 'commit'])
        ->name('lease-documents.commit');

    Route::resource('lease-schedules', LeaseSchedulesController::class);
    Route::post('lease-schedules/{leaseSchedule}/mark-signed', [LeaseSchedulesController::class, 'markSigned'])
        ->name('lease-schedules.mark-signed');
    Route::get('lease-schedules/{leaseSchedule}/annexure-diff', [LeaseSchedulesController::class, 'annexureDiff'])
        ->name('lease-schedules.annexure-diff')
        ->breadcrumbs(fn (Trail $trail, $leaseSchedule) => $trail->parent('home')
            ->push(trans('admin/lease-schedules/general.lease_schedule'), route('lease-schedules.show', $leaseSchedule))
            ->push(trans('admin/lease-schedules/general.annexure_diff'), route('lease-schedules.annexure-diff', $leaseSchedule)));

    /*
    * Depreciations
     */
    Route::resource('depreciations', DepreciationsController::class);

    /*
    * Toners — printer/consumable stock dashboard
    */
    Route::get('toners', [TonersController::class, 'index'])->name('toners.index');

    /*
    * Status Labels
     */
    Route::resource('statuslabels', StatuslabelsController::class);

    /*
    * Departments
    */
    Route::resource('departments', DepartmentsController::class);
});

/*
|
|--------------------------------------------------------------------------
| Re-Usable Modal Dialog routes.
|--------------------------------------------------------------------------
|
| Routes for various modal dialogs to interstitially create various things
|
*/

Route::group(['middleware' => 'auth', 'prefix' => 'modals'], function () {
    Route::get('{type}/{itemId?}', [ModalController::class, 'show'])->name('modal.show');
});

/*
|--------------------------------------------------------------------------
| Log Routes
|--------------------------------------------------------------------------
|
| Register all the admin routes.
|
*/

Route::group(['middleware' => 'auth'], function () {
    Route::get(
        'display-sig/{filename}',
        [ActionlogController::class, 'displaySig']
    )->name('log.signature.view');
    Route::get(
        'stored-eula-file/{filename}',
        [ActionlogController::class, 'getStoredEula']
    )->name('log.storedeula.download');
});

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
|
| Register all the admin routes.
|
*/

Route::group(['prefix' => 'admin', 'middleware' => ['auth', 'authorize:superuser']], function () {

    Route::get('toolbar', [\App\Http\Controllers\Admin\ToolbarController::class, 'edit'])
        ->name('admin.toolbar.edit');
    Route::post('toolbar', [\App\Http\Controllers\Admin\ToolbarController::class, 'update'])
        ->name('admin.toolbar.update');

    Route::get('settings', [SettingsController::class, 'getSettings'])
        ->name('settings.general.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
            ->push(trans('admin/settings/general.general_title'), route('settings.general.index')));

    Route::post('settings', [SettingsController::class, 'postSettings'])
        ->name('settings.general.save');

    Route::get('branding', [SettingsController::class, 'getBranding'])
        ->name('settings.branding.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
            ->push(trans('admin/settings/general.branding_title'), route('settings.branding.index')));

    Route::post('branding', [SettingsController::class, 'postBranding'])
        ->name('settings.branding.save');

    Route::get('security', [SettingsController::class, 'getSecurity'])
        ->name('settings.security.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
            ->push(trans('admin/settings/general.security_title'), route('settings.security.index')));

    Route::post('security', [SettingsController::class, 'postSecurity'])
        ->name('settings.security.save');

    Route::get('localization', [SettingsController::class, 'getLocalization'])
        ->name('settings.localization.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
            ->push(trans('admin/settings/general.localization_title'), route('settings.localization.index')));

    Route::post('localization', [SettingsController::class, 'postLocalization'])
        ->name('settings.localization.save');

    Route::get('notifications', [SettingsController::class, 'getAlerts'])
        ->name('settings.alerts.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
            ->push(trans('admin/settings/general.alert_title'), route('settings.alerts.index')));

    Route::post('notifications', [SettingsController::class, 'postAlerts'])
        ->name('settings.alerts.save');

    Route::get('agreements', [SettingsController::class, 'getAgreements'])
        ->name('settings.agreements.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
            ->push(trans('admin/settings/general.agreements_title'), route('settings.agreements.index')));

    Route::post('agreements', [SettingsController::class, 'postAgreements'])
        ->name('settings.agreements.save');

    Route::get('emails', [EmailsController::class, 'index'])
        ->name('settings.emails.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
            ->push(trans('admin/settings/general.emails'), route('settings.emails.index')));

    Route::get('emails/recipient-options', [EmailsController::class, 'recipientOptions'])
        ->name('settings.emails.recipient-options');

    Route::get('emails/{key}/preview', [EmailsController::class, 'preview'])
        ->name('settings.emails.preview');

    Route::post('emails', [EmailsController::class, 'save'])
        ->name('settings.emails.save');

    Route::post('emails/test', [EmailsController::class, 'test'])
        ->name('settings.emails.test');

    Route::get('slack', [SettingsController::class, 'getSlack'])
        ->name('settings.slack.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
            ->push(trans('admin/settings/general.webhook_title'), route('settings.slack.index')));

    Route::post('slack', [SettingsController::class, 'postSlack'])
        ->name('settings.slack.save');

    Route::get('asset_tags', [SettingsController::class, 'getAssetTags'])
        ->name('settings.asset_tags.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
            ->push(trans('admin/settings/general.asset_tag_title'), route('settings.asset_tags.index')));

    Route::post('asset_tags', [SettingsController::class, 'postAssetTags'])
        ->name('settings.asset_tags.save');

    Route::get('labels', [SettingsController::class, 'getLabels'])
        ->name('settings.labels.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
            ->push(trans('admin/settings/general.labels_title'), route('settings.labels.index')));

    Route::post('labels', [SettingsController::class, 'postLabels'])
        ->name('settings.labels.save');

    Route::get('ldap', [SettingsController::class, 'getLdapSettings'])
        ->name('settings.ldap.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
            ->push(trans('admin/settings/general.ldap_ad'), route('settings.ldap.index')));

    Route::post('ldap', [SettingsController::class, 'postLdapSettings'])
        ->name('settings.ldap.save');

    // URI form-settings, not forms: /admin/forms belongs to the forms
    // platform itself; this is the Settings copy editor for them.
    Route::get('form-settings', [SettingsController::class, 'getForms'])
        ->name('settings.forms.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
            ->push(trans('admin/forms/general.settings_title'), route('settings.forms.index')));

    Route::post('form-settings', [SettingsController::class, 'postForms'])
        ->name('settings.forms.save');

    Route::get('phpinfo', [SettingsController::class, 'getPhpInfo'])
        ->name('settings.phpinfo.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
            ->push(trans('admin/settings/general.php_info'), route('settings.phpinfo.index')));

    Route::get('oauth', [SettingsController::class, 'api'])
        ->name('settings.oauth.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
            ->push(trans('admin/settings/general.oauth'), route('settings.oauth.index')));

    Route::post('oauth/tokens/{token}/revoke', [SettingsController::class, 'revokePersonalAccessToken'])
        ->name('settings.oauth.tokens.revoke');

    Route::post('oauth/tokens/{token}/unrevoke', [SettingsController::class, 'unrevokePersonalAccessToken'])
        ->name('settings.oauth.tokens.unrevoke');

    Route::post('oauth/clients/{client}/revoke', [SettingsController::class, 'revokeOAuthClient'])
        ->name('settings.oauth.clients.revoke');

    Route::post('oauth/clients/{client}/unrevoke', [SettingsController::class, 'unrevokeOAuthClient'])
        ->name('settings.oauth.clients.unrevoke');

    Route::get('google', [SettingsController::class, 'getGoogleLoginSettings'])
        ->name('settings.google.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
            ->push(trans('admin/settings/general.google_login'), route('settings.google.index')));

    Route::post('google', [SettingsController::class, 'postGoogleLoginSettings'])
        ->name('settings.google.save');

    Route::get('purge', [SettingsController::class, 'getPurge'])
        ->name('settings.purge.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
            ->push(trans('admin/settings/general.purge'), route('settings.purge.index')));

    Route::post('purge', [SettingsController::class, 'postPurge'])
        ->name('settings.purge.save');

    Route::get('login-attempts', [SettingsController::class, 'getLoginAttempts'])
        ->name('settings.logins.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
            ->push(trans('admin/settings/general.login'), route('settings.logins.index')));

    // SAML
    Route::get('/saml', [SettingsController::class, 'getSamlSettings'])
        ->name('settings.saml.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
            ->push(trans('admin/settings/general.saml_title'), route('settings.saml.index')));

    Route::post('/saml', [SettingsController::class, 'postSamlSettings'])
        ->name('settings.saml.save');

    // Backups
    Route::group(['prefix' => 'backups', 'middleware' => 'auth'], function () {
        Route::get('download/{filename}',
            [SettingsController::class, 'downloadFile'])->name('settings.backups.download');

        Route::delete('delete/{filename}',
            [SettingsController::class, 'deleteFile'])->name('settings.backups.destroy');

        Route::post('/',
            [SettingsController::class, 'postBackups']
        )->name('settings.backups.create');

        Route::post('/restore/{filename}',
            [SettingsController::class, 'postRestore']
        )->name('settings.backups.restore');

        Route::post('/upload',
            [SettingsController::class, 'postUploadBackup']
        )->name('settings.backups.upload');

        // Handle redirect from after POST request from backup restore
        Route::get('/restore/{filename?}', function () {
            return redirect(route('settings.backups.index'));
        });

        Route::get('/', [SettingsController::class, 'getBackups'])
            ->name('settings.backups.index')
            ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
                ->push(trans('admin/settings/general.backups'), route('settings.backups.index')));
    });

    Route::get('groups/audit', [GroupsController::class, 'audit'])
        ->name('groups.audit')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('groups.index')
            ->push(trans('admin/groups/general.audit_title'), route('groups.audit')));

    // "Who can see this page?" — reached from the lock on every sensitive
    // page. Inherits this group's superuser-only middleware: the roster is
    // the map of who holds access to the money and contract pages.
    Route::get('access-audit', [AccessAuditController::class, 'show'])
        ->name('access-audit.show')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('settings.index')
            ->push(trans('admin/access-audit/general.title'), route('access-audit.show')));

    Route::resource('groups', GroupsController::class);

    Route::resource('license-models', LicenseModelsController::class, [
        'names' => [
            'index' => 'license-models.index',
            'create' => 'license-models.create',
            'store' => 'license-models.store',
            'show' => 'license-models.show',
            'edit' => 'license-models.edit',
            'update' => 'license-models.update',
            'destroy' => 'license-models.destroy',
        ],
        'parameters' => ['license-models' => 'licenseModel'],
    ]);

    /**
     * This breadcrumb is repeated for groups in the BreadcrumbServiceProvider, since groups uses resource routes
     * and that servcie provider cannot see the breadcrumbs defined below
     */
    Route::get('/', [SettingsController::class, 'index'])
        ->name('settings.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.admin'), route('settings.index')));
});

/*
|--------------------------------------------------------------------------
| Importer Routes
|--------------------------------------------------------------------------
|
|
|
*/

Route::group(['prefix' => 'import', 'middleware' => ['auth']], function () {

    Route::get('download/{import}',
        [
            UploadedFilesController::class,
            'downloadImport',
        ]
    )->name('imports.download');

    Route::livewire('/', Importer::class)
        ->middleware('auth')
        ->name('imports.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.import'), route('imports.index')));

});

/*
|--------------------------------------------------------------------------
| Account Routes
|--------------------------------------------------------------------------
|
|
|
*/
Route::group(['prefix' => 'account', 'middleware' => ['auth']], function () {

    // Profile
    Route::get('profile', [ProfileController::class, 'getIndex'])
        ->name('profile')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.editprofile'), route('profile')));

    Route::post('profile', [ProfileController::class, 'postIndex'])
        ->name('profile.update');

    Route::get('menu', [ProfileController::class, 'getMenuState'])
        ->name('account.menuprefs');

    Route::get('password', [ProfileController::class, 'password'])
        ->name('account.password.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.profile'), route('account'))
            ->push(trans('general.changepassword'), route('account.password.index')));

    Route::post('password', [ProfileController::class, 'passwordSave'])
        ->name('account.password.update');

    Route::get('api', [ProfileController::class, 'api'])
        ->name('user.api')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.profile'), route('account'))
            ->push(trans('general.manage_api_keys'), route('user.api')));

    // View Assets
    Route::get('view-assets', [ViewAssetsController::class, 'getIndex'])
        ->name('view-assets')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.profile'), route('account'))
            ->push(trans('general.viewassets'), route('view-assets')));

    Route::get('requested', [ViewAssetsController::class, 'getRequestedAssets'])
        ->name('account.requested')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.profile'), route('account'))
            ->push(trans('general.requested_assets_menu'), route('account.requested')));

    Route::get(
        'requestable-assets', [ViewAssetsController::class, 'getRequestableIndex'])
        ->name('requestable-assets')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.requestable_items'), route('requestable-assets')));

    Route::post('request-asset/{asset}', [ViewAssetsController::class, 'store'])
        ->name('account.request-asset');

    Route::post('request-asset/{asset}/cancel', [ViewAssetsController::class, 'destroy'])
        ->name('account.request-asset.cancel');

    Route::post('request/{itemType}/{itemId}/{cancel_by_admin?}/{requestingUser?}', [ViewAssetsController::class, 'getRequestItem'])
        ->name('account/request-item');

    Route::get(
        'display-sig/{filename}',
        [ProfileController::class, 'displaySig']
    )->name('profile.signature.view');

    Route::get(
        'stored-eula-file/{filename}',
        [ProfileController::class, 'getStoredEula']
    )->name('profile.storedeula.download');

    // Account Dashboard
    Route::get('/', [ViewAssetsController::class, 'getIndex'])
        ->name('account');

    Route::get('accept', [Account\AcceptanceController::class, 'index'])
        ->name('account.accept')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.profile'), route('account'))
            ->push(trans('general.accept_items'), route('account.accept')));

    Route::get('accept/{acceptance}', [Account\AcceptanceController::class, 'create'])
        ->name('account.accept.item')
        ->breadcrumbs(fn (Trail $trail, mixed $acceptance) => BuildAcceptanceBreadcrumbs::forAcceptance($trail, $acceptance));

    Route::post('accept/{acceptance}', [Account\AcceptanceController::class, 'store'])
        ->name('account.store-acceptance');

    Route::get(
        'print',
        [
            ProfileController::class,
            'printInventory',
        ]
    )->name('profile.print');

    Route::post(
        'email',
        [
            ProfileController::class,
            'emailAssetList',
        ]
    )->name('profile.email_assets');

});

/*
| Purchase orders resolve by their number — /procurement/purchase-orders/P0026022
| — because that is what finance, the vendor and every PDF call them. An integer
| still resolves, so links written before the change, and any code that passes an
| id rather than a model, keep working instead of 404ing.
*/
Route::bind('purchase_order', function ($value) {
    return \App\Models\PurchaseOrder::where('po_number', $value)->first()
        ?? \App\Models\PurchaseOrder::findOrFail($value);
});

Route::group(['middleware' => ['auth']], function () {
    /*
    | Procurement moved under one prefix — /procurement/purchase-orders,
    | /procurement/requisitions, /procurement/orders,
    | /procurement/lease-decisions, /procurement/lessor-breakdown — because it
    | is one workflow and was scattered across the URL root. Route names did
    | not change, so every link in the app followed automatically; these
    | redirects are for what the app does not control: bookmarks, links in
    | emails already sent, and references in older PDFs.
    */
    foreach (['purchase-orders', 'requisitions', 'orders', 'lease-decisions'] as $legacyProcurementPath) {
        Route::redirect($legacyProcurementPath, '/procurement/'.$legacyProcurementPath, 301);
        Route::redirect($legacyProcurementPath.'/{tail}', '/procurement/'.$legacyProcurementPath.'/{tail}', 301)
            ->where('tail', '.*');
    }

    // The lease portfolio has moved twice: off the reports hub, then from
    // "lessor breakdown" to the page it grew into.
    Route::redirect('reports/lessor-breakdown', '/procurement/leasing', 301);
    Route::redirect('procurement/lessor-breakdown', '/procurement/leasing', 301);

    // The agreements hub used to be called the user-agreement ledger.
    Route::redirect('procurement/user-agreement-ledger', '/procurement/agreements', 301);

    // The leases pages were "operational" and "financial" once.
    Route::redirect('procurement/leases-operational', '/procurement/leases-hardware', 301);
    Route::redirect('procurement/leases-financial', '/procurement/leases-contracts', 301);

    // A wave is part of deployments, so it lives under /deployments/waves. The
    // old top-level path is in links already sent.
    Route::redirect('deployment-waves', '/deployments/waves', 301);
    Route::redirect('deployment-waves/{tail}', '/deployments/waves/{tail}', 301)->where('tail', '.*');

    Route::post('notes', [NotesController::class, 'store'])->name('notes.store');
});

// Forms moved from /procurement/forms to /admin/forms — the platform is
// administration (forms attach to waves; there may be many). The old
// prefix 301s below because form links live in sent announcement emails.
Route::group(['middleware' => ['auth']], function () {
    Route::get('procurement/forms/{path?}', function (?string $path = null) {
        $query = request()->getQueryString();

        return redirect('/admin/forms'.($path ? '/'.$path : '').($query ? '?'.$query : ''), 301);
    })->where('path', '.*');
});

Route::group(['middleware' => ['auth'], 'prefix' => 'admin/forms'], function () {
    Route::get('/', [FormsController::class, 'index'])
        ->name('forms.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('admin/forms/general.title'), route('forms.index')));

    Route::get('{slug}', [FormsController::class, 'show'])
        ->name('forms.show')
        ->breadcrumbs(fn (Trail $trail, string $slug) => $trail->parent('forms.index')
            ->push(FormRegistry::find($slug)
                ? trans(FormRegistry::modules()[$slug]['label_key'])
                : $slug, route('forms.show', $slug)));

    Route::post('{slug}', [FormsController::class, 'submit'])
        ->name('forms.submit');

    Route::get('{slug}/success', [FormsController::class, 'success'])
        ->name('forms.success')
        ->breadcrumbs(fn (Trail $trail, string $slug) => $trail->parent('forms.show', $slug)
            ->push(trans('admin/forms/general.success_crumb'), route('forms.success', $slug)));

    Route::get('{slug}/submissions', [FormsController::class, 'submissionsIndex'])
        ->name('forms.submissions.index')
        ->breadcrumbs(fn (Trail $trail, string $slug) => $trail->parent('forms.show', $slug)
            ->push(trans('admin/forms/general.submissions'), route('forms.submissions.index', $slug)));

    Route::get('{slug}/submissions/{id}', [FormsController::class, 'submissionShow'])
        ->name('forms.submissions.show')
        ->breadcrumbs(fn (Trail $trail, string $slug, $id) => $trail->parent('forms.submissions.index', $slug)
            ->push('#'.$id, route('forms.submissions.show', [$slug, $id])));
});

// Legacy /user-form URLs preserved as redirects so any external links
// (emails, bookmarks) keep working after the /forms pivot.
Route::group(['middleware' => ['auth']], function () {
    Route::get('user-form', fn () => redirect()->route('forms.show', 'faculty-program'))
        ->name('user-form.show');
    Route::get('user-form/success', fn () => redirect()->route('forms.success', 'faculty-program'))
        ->name('user-form.success');

    // Forms moved under /procurement; the old /forms URLs live in sent
    // emails and bookmarks, so they redirect rather than 404.
    Route::get('forms', fn () => redirect()->route('forms.index'));
    Route::get('forms/{path}', fn (string $path) => redirect('/procurement/forms/'.$path))
        ->where('path', '.*');
});

Route::group(['prefix' => 'reports', 'middleware' => ['auth']], function () {

    Route::get('/', [ReportsController::class, 'index'])
        ->name('reports.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.reports'), route('reports.index')));

    Route::get('audit', [ReportsController::class, 'audit'])
        ->name('reports.audit')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.reports'), route('reports.index'))
            ->push(trans('general.audit_report'), route('reports.audit')));

    Route::get(
        'depreciation', [ReportsController::class, 'getDeprecationReport'])
        ->name('reports/depreciation')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.reports'), route('reports.index'))
            ->push(trans('general.depreciation_report'), route('reports/depreciation')));

    // Is this still used??
    Route::get(
        'export/depreciation', [ReportsController::class, 'exportDeprecationReport'])
        ->name('reports/export/depreciation')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.reports'), route('reports.index'))
            ->push(trans('general.depreciation_report'), route('reports.audit')));

    Route::get(
        'maintenances', [ReportsController::class, 'getMaintenancesReport'])
        ->name('ui.reports.maintenances')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.reports'), route('reports.index'))
            ->push(trans('general.asset_maintenance_report'), route('ui.reports.maintenances')));

    // Is this still used?
    Route::get('export/maintenances', [ReportsController::class, 'exportMaintenancesReport'])
        ->name('reports/export/maintenances')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.reports'), route('reports.index'))
            ->push(trans('general.asset_maintenance_report'), route('reports/export/maintenances')));

    Route::get('licenses', [ReportsController::class, 'getLicenseReport'])
        ->name('reports/licenses')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.reports'), route('reports.index'))
            ->push(trans('general.license_report'), route('reports/licenses')));

    // @TODO this should be a GET?
    Route::get('export/licenses', [ReportsController::class, 'exportLicenseReport'])
        ->name('reports/export/licenses');

    Route::get('accessories', [ReportsController::class, 'getAccessoryReport'])
        ->name('reports/accessories')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.reports'), route('reports.index'))
            ->push(trans('general.accessory_report'), route('reports/accessories')));

    Route::get('export/accessories', [ReportsController::class, 'exportAccessoryReport'])
        ->name('reports/export/accessories');

    Route::get('custom', [ReportsController::class, 'getCustomReport'])
        ->name('reports/custom')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.reports'), route('reports.index'))
            ->push(trans('general.custom_report'), route('reports/custom')));

    Route::post('custom', [ReportsController::class, 'postCustom'])
        ->name('reports.post-custom');

    Route::prefix('templates')
        ->group(function () {

            Route::post('/', [ReportTemplatesController::class, 'store'])
                ->name('report-templates.store');

            // The breadcrumb on this is a little odd for now since we don't have a template index
            Route::get('/{reportTemplate}', [ReportTemplatesController::class, 'show'])
                ->name('report-templates.show')
                ->breadcrumbs(fn (Trail $trail, ReportTemplate $reportTemplate) => $trail->parent('reports/custom')
                    ->push($reportTemplate->name, null)
                    ->push(trans('general.customize_report'), ''));

            Route::get('/{reportTemplate}/edit', [ReportTemplatesController::class, 'edit'])
                ->name('report-templates.edit')
                ->breadcrumbs(fn (Trail $trail, ReportTemplate $reportTemplate) => $trail->parent('reports/custom')
                    ->push($reportTemplate->name, route('report-templates.show', $reportTemplate))
                    ->push(trans('general.customize_report'), ''));

            Route::post('/{reportTemplate}', [ReportTemplatesController::class, 'update'])
                ->name('report-templates.update');

            Route::delete('/{reportTemplate}', [ReportTemplatesController::class, 'destroy'])
                ->name('report-templates.destroy');
        });

    Route::get(
        'activity', [ReportsController::class, 'getActivityReport'])
        ->name('reports.activity')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.reports'), route('reports.index'))
            ->push(trans('general.activity_report'), route('reports.activity')));

    Route::post('activity', [ReportsController::class, 'postActivityReport'])
        ->name('reports.activity.post');

    Route::get('unaccepted_assets/{deleted?}', [ReportsController::class, 'getAssetAcceptanceReport'])
        ->name('reports/unaccepted_assets')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.reports'), route('reports.index'))
            ->push(trans('general.unaccepted_asset_report'), route('reports/unaccepted_assets')));

    Route::post('unaccepted_assets/sent_reminder', [ReportsController::class, 'sentAssetAcceptanceReminder'])
        ->name('reports/unaccepted_assets_sent_reminder');

    Route::delete('unaccepted_assets/{acceptanceId}/delete', [ReportsController::class, 'deleteAssetAcceptance'])
        ->name('reports/unaccepted_assets_delete');

    Route::post(
        'unaccepted_assets/{deleted?}', [ReportsController::class, 'postAssetAcceptanceReport'])
        ->name('reports/export/unaccepted_assets');

    // Lessor Breakdown now lives with the rest of procurement, at
    // /procurement/lessor-breakdown.

    // The procurement board moved out to /procurement (route names kept).

    Route::prefix('transactions')->group(function () {
        $txCrumb = function (string $routeName, string $titleKey) {
            return fn (Trail $trail) => $trail->parent('reports.transactions.index')
                ->push(trans("admin/reports/transactions.$titleKey"), route($routeName));
        };

        Route::get('/', [TransactionsReportsController::class, 'index'])
            ->name('reports.transactions.index')
            ->middleware('can:reports.transactions.view')
            ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
                ->push(trans('general.reports'), route('reports.index'))
                ->push(trans('admin/reports/transactions.dashboard_title'), route('reports.transactions.index')));

        Route::get('reconciliations', [TransactionsReportsController::class, 'reconciliations'])
            ->name('reports.transactions.reconciliations')
            ->middleware('can:reports.transactions.view')
            ->breadcrumbs($txCrumb('reports.transactions.reconciliations', 'crumb_reconciliations'));

        Route::get('reconciliations/{ym}', [TransactionsReportsController::class, 'show'])
            ->name('reports.transactions.show')
            ->middleware('can:reports.transactions.view')
            ->where('ym', '\\d{4}-\\d{1,2}')
            ->breadcrumbs(fn (Trail $trail, string $ym) => $trail
                ->parent('reports.transactions.reconciliations')
                ->push(trans('admin/reports/transactions.crumb_period', ['ym' => $ym]), route('reports.transactions.show', $ym)));

        Route::get('gl-breakdown', [TransactionsReportsController::class, 'glBreakdown'])
            ->name('reports.transactions.gl-breakdown')
            ->middleware('can:reports.transactions.gl')
            ->breadcrumbs($txCrumb('reports.transactions.gl-breakdown', 'crumb_gl_breakdown'));

        Route::get('mail-room', [TransactionsReportsController::class, 'mailRoom'])
            ->name('reports.transactions.mail-room')
            ->middleware('can:reports.transactions.mailroom')
            ->breadcrumbs($txCrumb('reports.transactions.mail-room', 'crumb_mail_room'));

        Route::get('refunds', [TransactionsReportsController::class, 'refunds'])
            ->name('reports.transactions.refunds')
            ->middleware('can:reports.transactions.refunds')
            ->breadcrumbs($txCrumb('reports.transactions.refunds', 'crumb_refunds'));

        Route::get('self-serve', [TransactionsReportsController::class, 'selfServe'])
            ->name('reports.transactions.self-serve')
            ->middleware('can:reports.transactions.view')
            ->breadcrumbs($txCrumb('reports.transactions.self-serve', 'crumb_self_serve'));

        Route::get('line-items', [TransactionsReportsController::class, 'lineItems'])
            ->name('reports.transactions.line-items')
            ->middleware('can:reports.transactions.view')
            ->breadcrumbs($txCrumb('reports.transactions.line-items', 'crumb_line_items'));

        Route::get('overrides', [TransactionsReportsController::class, 'overrides'])
            ->name('reports.transactions.overrides')
            ->middleware('can:reports.transactions.overrides')
            ->breadcrumbs($txCrumb('reports.transactions.overrides', 'crumb_overrides'));

        Route::post('overrides', [TransactionsReportsController::class, 'storeOverride'])
            ->name('reports.transactions.overrides.store')
            ->middleware('can:reports.transactions.overrides');

        Route::delete('overrides/{id}', [TransactionsReportsController::class, 'deleteOverride'])
            ->name('reports.transactions.overrides.delete')
            ->middleware('can:reports.transactions.overrides')
            ->whereNumber('id');
    });

    // Contracts used to have a dashboard here. It was folded into /contracts,
    // which now carries the tiles, charts, drill-down reports and register in
    // one page; the redirects live in routes/web/contracts.php.

    Route::get('printing', [PrintingReportsController::class, 'index'])
        ->name('reports.printing')
        ->middleware('can:view,App\Models\Asset')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.reports'), route('reports.index'))
            ->push(trans('admin/reports/printing.dashboard_title'), route('reports.printing')));

    // The exhibit board moved out to /deployments/exhibits — it is a
    // deployment of existing equipment, not a report.
    Route::get('exhibit', function () {
        $query = request()->getQueryString();

        return redirect('/deployments/exhibits'.($query ? '?'.$query : ''), 301);
    });

    // The Deployments board moved out to /deployments (route name kept).
    Route::get('deployments', function () {
        $query = request()->getQueryString();

        return redirect('/deployments'.($query ? '?'.$query : ''), 301);
    });

    Route::get('fleet-health', [FleetHealthReportsController::class, 'index'])
        ->name('reports.fleet-health')
        ->middleware('can:reports.fleet-health.view')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('general.reports'), route('reports.index'))
            ->push(trans('admin/reports/general.fleet_health'), route('reports.fleet-health')));

});

/*
| Deployments + Procurement, elevated: first-class modules at /deployments
| and /procurement rather than filed under /reports. Route names are
| unchanged so every existing link keeps working; the old /reports/… URLs
| redirect permanently. Access is read (….view) vs read+write (….edit) —
| AuthServiceProvider carries compat fallbacks for existing grants.
*/
Route::group(['middleware' => ['auth']], function () {

    Route::get('deployments', [DeploymentsController::class, 'report'])
        ->name('reports.deployments')
        ->middleware('can:deployments.view')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
            ->push(trans('admin/deployments/general.dashboard_title'), route('reports.deployments')));

    // Query strings survive the hop — old bookmarked deep links carry
    // params (?requisition=…, ?fiscal_year=…) that Route::redirect drops.
    Route::get('reports/procurement/{path?}', function (string $path = '') {
        $query = request()->getQueryString();

        return redirect('/procurement'.($path !== '' ? '/'.$path : '').($query ? '?'.$query : ''), 301);
    })->where('path', '.*');

    Route::prefix('procurement')->group(function () {
        // Each procurement report's breadcrumb chains off the procurement
        // landing — same Home > Reports > Procurement Reports > <Title> shape.
        // A plain function, not a one-line curried arrow fn: closure
        // serialization extracts source by start line, and two arrow fns
        // beginning on the same line make it grab the wrong one.
        $crumb = function (string $routeName, string $titleKey) {
            return fn (Trail $trail) => $trail->parent('reports.procurement')
                ->push(trans("admin/purchase-orders/general.$titleKey"), route($routeName));
        };

        Route::get('/', [ProcurementReportsController::class, 'index'])
            ->name('reports.procurement')
            ->middleware('can:procurement.view')
            ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')
                ->push(trans('general.procurement'), route('reports.procurement')));

        Route::patch('visibility', [ProcurementReportsController::class, 'updateVisibility'])
            ->name('reports.procurement.visibility');

        Route::post('budget-allocations', [BudgetAllocationsController::class, 'store'])
            ->name('budget_allocations.store');
        Route::delete('budget-allocations/{budget_allocation}', [BudgetAllocationsController::class, 'destroy'])
            ->name('budget_allocations.destroy');

        // The builder moved to /purchase-orders/builder — it is an
        // operational tool, not a report. Old links keep working.
        Route::get('po-builder', function () {
            return redirect()->route('purchase-orders.builder', request()->query());
        });
        // The lease portfolio page — charts, lessor breakdown and the
        // year's rent costs. The route name predates the page's growth
        // from a single breakdown table.
        Route::get('leasing', [ProcurementReportsController::class, 'lessorBreakdown'])
            ->name('reports.lessor-breakdown')
            ->breadcrumbs($crumb('reports.lessor-breakdown', 'leasing_title'));
        // One lease's own page, addressed by the contract id itself.
        Route::get('leasing/{contract}', [ProcurementReportsController::class, 'leaseDetail'])
            ->name('reports.procurement.lease-detail')
            ->where('contract', '[A-Za-z0-9._-]+')
            ->breadcrumbs(fn (Trail $trail, $contract) => $trail
                ->parent('reports.lessor-breakdown')
                ->push($contract, route('reports.procurement.lease-detail', $contract)));
        Route::get('rent-costs', [ProcurementReportsController::class, 'rentCosts'])
            ->name('reports.procurement.rent-costs')
            ->breadcrumbs($crumb('reports.procurement.rent-costs', 'report_rent_costs'));
        // The year's capital ask, as one link for finance.
        Route::get('capital', [ProcurementReportsController::class, 'capitalRequest'])
            ->name('reports.procurement.capital-request')
            ->breadcrumbs($crumb('reports.procurement.capital-request', 'capital_request_title'));
        Route::post('capital/draft', [ProcurementReportsController::class, 'capitalRequestDraft'])
            ->name('reports.procurement.capital-request.draft');
        Route::post('capital/lines', [ProcurementReportsController::class, 'capitalRequestLineStore'])
            ->name('reports.procurement.capital-request.lines.store');
        Route::delete('capital/lines/{line}', [ProcurementReportsController::class, 'capitalRequestLineDestroy'])
            ->name('reports.procurement.capital-request.lines.destroy');
        Route::get('po-budget', [ProcurementReportsController::class, 'poBudget'])
            ->name('reports.procurement.po-budget')
            ->breadcrumbs($crumb('reports.procurement.po-budget', 'report_po_budget'));
        Route::get('invoices', [ProcurementReportsController::class, 'invoices'])
            ->name('reports.procurement.invoices')
            ->breadcrumbs($crumb('reports.procurement.invoices', 'report_invoices'));
        Route::get('receiving', [ProcurementReportsController::class, 'receiving'])
            ->name('reports.procurement.receiving');
        Route::get('tax', [ProcurementReportsController::class, 'tax'])
            ->name('reports.procurement.tax');
        // Capital Spend (the ledger) moved aside so /procurement/capital
        // could be the Capital Request — the link that goes to finance.
        Route::get('capital-spend', [ProcurementReportsController::class, 'capital'])
            ->name('reports.procurement.capital')
            ->breadcrumbs($crumb('reports.procurement.capital', 'report_capital'));
        Route::get('refresh-forecast', [ProcurementReportsController::class, 'refreshForecast'])
            ->name('reports.procurement.forecast')
            ->breadcrumbs($crumb('reports.procurement.forecast', 'report_forecast'));
        Route::post('refresh-forecast/planned-order', [ProcurementReportsController::class, 'createPlannedOrder'])
            ->name('reports.procurement.forecast.plan');
        // "Leases", not "leased", in both name and path.
        Route::get('leases-hardware', [ProcurementReportsController::class, 'leasesOperational'])
            ->name('reports.procurement.leases-operational')
            ->breadcrumbs($crumb('reports.procurement.leases-operational', 'report_leases_operational'));
        Route::get('leases-contracts', [ProcurementReportsController::class, 'leasesFinancial'])
            ->name('reports.procurement.leases-financial')
            ->breadcrumbs($crumb('reports.procurement.leases-financial', 'report_leases_financial'));
        Route::get('lease-data-health', [ProcurementReportsController::class, 'leaseDataHealth'])
            ->name('reports.procurement.lease-data-health')
            ->breadcrumbs($crumb('reports.procurement.lease-data-health', 'report_lease_data_health'));
        Route::get('schedule-reconciliation', [ProcurementReportsController::class, 'csiSchedule'])
            ->name('reports.procurement.csi-schedule')
            ->breadcrumbs($crumb('reports.procurement.csi-schedule', 'report_csi_schedule'));
        Route::get('lease-reconciliation', [ProcurementReportsController::class, 'csiReconciliation'])
            ->name('reports.procurement.csi-reconciliation')
            ->breadcrumbs($crumb('reports.procurement.csi-reconciliation', 'report_csi_reconciliation'));
        Route::get('incoming-lease-assets', [ProcurementReportsController::class, 'csiArrivals'])
            ->name('reports.procurement.csi-arrivals')
            ->breadcrumbs($crumb('reports.procurement.csi-arrivals', 'report_csi_arrivals'));
        // Legacy vendor-named paths — permanent redirects to the neutral slugs.
        Route::redirect('csi-schedule', 'schedule-reconciliation', 301);
        Route::redirect('csi-reconciliation', 'lease-reconciliation', 301);
        Route::redirect('csi-arrivals', 'incoming-lease-assets', 301);
        Route::get('invoice-approval', [ProcurementReportsController::class, 'invoiceApproval'])
            ->name('reports.procurement.invoice-approval')
            ->breadcrumbs($crumb('reports.procurement.invoice-approval', 'report_invoice_approval'));
        Route::patch('invoice-approval/{invoice}', [ProcurementReportsController::class, 'updateInvoiceApproval'])
            ->name('reports.procurement.invoice-approval.update');
        Route::get('procurement/lease-decisions', [ProcurementReportsController::class, 'leaseDecisions'])
            ->name('reports.procurement.lease-decisions')
            ->breadcrumbs($crumb('reports.procurement.lease-decisions', 'report_lease_decisions'));
        Route::get('po-disposition', [ProcurementReportsController::class, 'poDisposition'])
            ->name('reports.procurement.po-disposition')
            ->breadcrumbs($crumb('reports.procurement.po-disposition', 'report_po_disposition'));
        Route::get('extension-watch', [ProcurementReportsController::class, 'extensionWatch'])
            ->name('reports.procurement.extension-watch')
            ->breadcrumbs($crumb('reports.procurement.extension-watch', 'report_extension_watch'));
        Route::get('aro-register', [ProcurementReportsController::class, 'aroRegister'])
            ->name('reports.procurement.aro-register')
            ->breadcrumbs($crumb('reports.procurement.aro-register', 'report_aro_register'));
        Route::get('asset-lease-detail', [ProcurementReportsController::class, 'assetLeaseDetail'])
            ->name('reports.procurement.asset-lease-detail')
            ->breadcrumbs($crumb('reports.procurement.asset-lease-detail', 'report_asset_lease_detail'));
        Route::get('po-drilldown', [ProcurementReportsController::class, 'poDrilldown'])
            ->name('reports.procurement.po-drilldown')
            ->breadcrumbs($crumb('reports.procurement.po-drilldown', 'report_po_drilldown'));
        Route::get('disposition-grid', [ProcurementReportsController::class, 'dispositionGrid'])
            ->name('reports.procurement.disposition-grid')
            ->breadcrumbs($crumb('reports.procurement.disposition-grid', 'report_disposition_grid'));
        Route::post('disposition-grid/note', [ProcurementReportsController::class, 'updateDispositionNote'])
            ->name('reports.procurement.disposition-grid.note');
        Route::post('disposition-grid/update', [ProcurementReportsController::class, 'updateDispositionAssets'])
            ->name('reports.procurement.disposition-grid.update');
        Route::post('note', [ProcurementReportsController::class, 'updateReportNote'])
            ->name('reports.procurement.note');
        Route::get('credit-ledger', [ProcurementReportsController::class, 'creditTerminationLedger'])
            ->name('reports.procurement.credit-ledger')
            ->breadcrumbs($crumb('reports.procurement.credit-ledger', 'report_credit_ledger'));
        Route::get('pst-applicability', [ProcurementReportsController::class, 'pstApplicability'])
            ->name('reports.procurement.pst-applicability')
            ->breadcrumbs($crumb('reports.procurement.pst-applicability', 'report_pst_applicability'));
        // The agreements hub. The route NAME keeps its history; the PATH is
        // the short noun people type.
        Route::get('agreements', [ProcurementReportsController::class, 'userAgreementLedger'])
            ->name('reports.procurement.user-agreement-ledger')
            ->breadcrumbs($crumb('reports.procurement.user-agreement-ledger', 'report_user_agreement_ledger'));
        Route::get('lease-end-schedules', [ProcurementReportsController::class, 'leaseEndSchedulesReport'])
            ->name('reports.procurement.lease-end-schedules')
            ->breadcrumbs($crumb('reports.procurement.lease-end-schedules', 'report_lease_end_schedules'));
        Route::get('schedule-signing', [ProcurementReportsController::class, 'scheduleSigningQueue'])
            ->name('reports.procurement.schedule-signing')
            ->breadcrumbs($crumb('reports.procurement.schedule-signing', 'report_schedule_signing'));
    });
});

Route::get(
    'auth/signin',
    [LoginController::class, 'legacyAuthRedirect']
);

/*
|--------------------------------------------------------------------------
| Setup Routes
|--------------------------------------------------------------------------
|
|
|
*/
Route::group(['prefix' => 'setup', 'middleware' => 'web'], function () {
    Route::get(
        'user',
        [SetupController::class, 'getSetupUser']
    )->name('setup.user');

    Route::post(
        'user',
        [SetupController::class, 'postSaveFirstAdmin']
    )->name('setup.user.save');

    Route::post(
        'migrate',
        [SetupController::class, 'SetupMigrate']
    )->name('setup.migrate');

    Route::get(
        'done',
        [SetupController::class, 'getSetupDone']
    )->name('setup.done');

    Route::get(
        'mailtest',
        [SettingsController::class, 'ajaxTestEmail']
    )->name('setup.mailtest');

    Route::get(
        '/',
        [SetupController::class, 'getSetupIndex']
    )->name('setup');
});

Route::group(['middleware' => 'web'], function () {

    Route::get(
        'login',
        [LoginController::class, 'showLoginForm']
    )->name('login');

    Route::post(
        'login',
        [LoginController::class, 'login']
    );

    Route::get(
        'two-factor-enroll',
        [LoginController::class, 'getTwoFactorEnroll']
    )->name('two-factor-enroll');

    Route::get(
        'two-factor',
        [LoginController::class, 'getTwoFactorAuth']
    )->name('two-factor');

    Route::post(
        'two-factor',
        [LoginController::class, 'postTwoFactorAuth']
    );

    Route::post(
        'password/email',
        [ForgotPasswordController::class, 'sendResetLinkEmail']
    )->name('password.email')->middleware('throttle:forgotten_password');

    Route::get(
        'password/reset',
        [ForgotPasswordController::class, 'showLinkRequestForm']
    )->name('password.request')->middleware('throttle:forgotten_password');

    Route::post(
        'password/reset',
        [ResetPasswordController::class, 'reset']
    )->name('password.update')->middleware('throttle:forgotten_password');

    Route::get(
        'password/reset/{token}',
        [ResetPasswordController::class, 'showResetForm']
    )->name('password.reset');

    Route::post(
        'password/email',
        [ForgotPasswordController::class, 'sendResetLinkEmail']
    )->name('password.email')->middleware('throttle:forgotten_password');

    // Socialite Google login
    Route::get('google', 'App\Http\Controllers\GoogleAuthController@redirectToGoogle')->name('google.redirect');
    Route::get('google/callback', 'App\Http\Controllers\GoogleAuthController@handleGoogleCallback')->name('google.callback');

    // need to keep GET /logout for SAML SLO
    Route::get(
        'logout',
        [LoginController::class, 'logout']
    )->name('logout.get');

    Route::post(
        'logout',
        [LoginController::class, 'logout']
    )->name('logout.post');

    /**
     * Uploaded files API routes
     */

    // Get a file
    Route::get('{object_type}/{id}/files/{file_id}',
        [
            UploadedFilesController::class,
            'show',
        ]
    )->name('ui.files.show')
        ->where(['object_type' => 'assets|audits|maintenances|hardware|models|users|locations|accessories|consumables|licenses|suppliers|components|companies|departments|purchase-orders|lease-schedules|contracts']);

    // Upload files(s)
    Route::post('{object_type}/{id}/files',
        [
            UploadedFilesController::class,
            'store',
        ]
    )->name('ui.files.store')
        ->where(['object_type' => 'assets|audits|maintenances|hardware|models|users|locations|accessories|consumables|licenses|suppliers|components|companies|departments|purchase-orders|lease-schedules|contracts']);

    // Delete files(s)
    Route::delete('{object_type}/{id}/files/{file_id}/delete',
        [
            UploadedFilesController::class,
            'destroy',
        ]
    )->name('ui.files.destroy')
        ->where(['object_type' => 'assets|maintenances|hardware|models|users|locations|accessories|consumables|licenses|suppliers|components|companies|departments|purchase-orders|lease-schedules|contracts']);
});

/*
|--------------------------------------------------------------------------
| Storage Proxy Route
|--------------------------------------------------------------------------
|
| When PUBLIC_S3_PROXY=true, public uploads (images, logos, avatars) are
| served through the application instead of directly from S3. This allows
| using a fully private S3 bucket for all storage.
|
*/
Route::get('storage-proxy/{path}', [StorageProxyController::class, 'show'])
    ->where('path', '.*')
    ->name('storage-proxy');

/**
 * Health check route - skip middleware
 */
Route::withoutMiddleware(['web'])->get(
    '/health',
    [HealthController::class, 'get']
)->name('health');

Route::middleware(['auth'])->get(
    '/',
    [DashboardController::class, 'index']
)->name('home')
    ->breadcrumbs(fn (Trail $trail) => $trail->push('Home', route('home'))
    );
