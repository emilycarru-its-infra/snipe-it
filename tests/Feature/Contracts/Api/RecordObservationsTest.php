<?php

namespace Tests\Feature\Contracts\Api;

use App\Models\Asset;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecordObservationsTest extends TestCase
{
    private const BASE = '/api/v1/workflow-observations/';

    private function rows(User $user, string $family, int $total): array
    {
        return $this->actingAsForApi($user)->getJson(self::BASE.$family)
            ->assertOk()->assertJsonPath('total', $total)->json('rows');
    }

    public function test_budget_allocations_keep_exact_decimals_and_leave_out_deleted_rows(): void
    {
        $author = User::factory()->create();
        $kept = DB::table('budget_allocations')->insertGetId(['fiscal_year' => 'FY27', 'area' => 'Example area',
            'amount' => '1234567.89', 'source' => 'forecast', 'description' => 'Example allocation',
            'effective_date' => '2026-04-01', 'created_by' => $author->id, 'created_at' => '2026-03-15 09:30:00', 'updated_at' => '2026-03-16 10:00:00']);
        DB::table('budget_allocations')->insert(['fiscal_year' => 'FY27', 'amount' => '1.00', 'deleted_at' => '2026-03-20 00:00:00']);

        $rows = $this->rows(User::factory()->create(['permissions' => '{"procurement.view":"1"}']), 'budget-allocations', 1);

        $this->assertSame([['id' => (string) $kept, 'fiscal_year' => 'FY27', 'area' => 'Example area', 'amount' => '1234567.89',
            'source' => 'forecast', 'description' => 'Example allocation', 'effective_date' => '2026-04-01',
            'created_by' => (string) $author->id, 'created_at' => '2026-03-15 09:30:00']], $rows);
    }

    public function test_store_orders_and_items_return_exactly_their_fields(): void
    {
        $requester = User::factory()->create();
        $order = DB::table('store_orders')->insertGetId(['user_id' => $requester->id, 'status' => 'quoted', 'program' => 'example',
            'quote_total' => '2499.9900', 'quote_expires_at' => '2026-06-30', 'shipped_at' => '2026-05-02 14:00:00', 'created_at' => '2026-05-01 08:00:00']);
        $item = DB::table('store_order_items')->insertGetId(['store_order_id' => $order, 'description' => 'Example laptop',
            'vendor_sku' => 'EXAMPLE-SKU-1', 'quantity' => 2, 'unit_cost' => '1249.9950', 'created_at' => '2026-05-01 08:00:00']);
        $reader = User::factory()->create(['permissions' => '{"orders.view":"1"}']);

        $orders = $this->rows($reader, 'store-orders', 1);
        $this->assertSame(['id', 'user_id', 'status', 'program', 'order_usage', 'location_id', 'refresh_asset_id', 'funding_account',
            'lease_schedule', 'gl_code', 'department_po_number', 'notes', 'decision_notes', 'decided_by', 'decided_at', 'requisition_id',
            'purchase_order_id', 'deployment_wave_id', 'vendor_sent_at', 'quote_number', 'quote_total', 'quote_expires_at',
            'quote_received_at', 'confirmed_at', 'tracking_number', 'shipped_at', 'arrived_at', 'created_at'], array_keys($orders[0]));
        $this->assertSame((string) $requester->id, $orders[0]['user_id']);
        $this->assertSame('2499.9900', $orders[0]['quote_total']);
        $this->assertSame('2026-06-30', $orders[0]['quote_expires_at']);
        $this->assertSame('2026-05-02 14:00:00', $orders[0]['shipped_at']);
        $this->assertNull($orders[0]['decided_by']);

        $this->assertSame([['id' => (string) $item, 'store_order_id' => (string) $order, 'catalog_item_id' => null,
            'description' => 'Example laptop', 'vendor_sku' => 'EXAMPLE-SKU-1', 'mfr_part_number' => null, 'quantity' => 2,
            'unit_cost' => '1249.9950', 'created_at' => '2026-05-01 08:00:00']], $this->rows($reader, 'store-order-items', 1));
    }

    public function test_deployment_items_drop_deleted_waves_and_keep_company_scope(): void
    {
        $this->settings->enableMultipleFullCompanySupport();
        $own = Company::factory()->create();
        $other = Company::factory()->create();
        $asset = Asset::factory()->create(['company_id' => $own->getKey(), 'asset_tag' => 'EXAMPLE-1']);
        $foreign = Asset::factory()->create(['company_id' => $other->getKey(), 'asset_tag' => 'EXAMPLE-2']);
        $wave = DB::table('deployment_waves')->insertGetId(['name' => 'Example wave', 'slug' => 'example-wave']);
        $retired = DB::table('deployment_waves')->insertGetId(['name' => 'Old wave', 'slug' => 'old-wave', 'deleted_at' => '2026-01-01 00:00:00']);
        $item = DB::table('deployment_items')->insertGetId(['wave_id' => $wave, 'asset_id' => $asset->getKey(), 'group_label' => 'Example group',
            'target_deploy_date' => '2026-08-15', 'deployed_at' => '2026-08-16 11:15:00', 'created_at' => '2026-07-01 00:00:00']);
        $unassigned = DB::table('deployment_items')->insertGetId(['wave_id' => $wave]);
        DB::table('deployment_items')->insert(['wave_id' => $retired, 'asset_id' => $asset->getKey()]);
        DB::table('deployment_items')->insert(['wave_id' => $wave, 'asset_id' => $foreign->getKey()]);

        $reader = User::factory()->create(['company_id' => $own->getKey(), 'permissions' => '{"deployments.view":"1"}']);
        $rows = $this->rows($reader, 'deployment-items', 2);

        $this->assertSame([(string) $item, (string) $unassigned], array_column($rows, 'id'));
        $this->assertSame(['id' => (string) $item, 'wave_id' => (string) $wave, 'asset_id' => (string) $asset->getKey(),
            'replaces_asset_id' => null, 'order_item_id' => null, 'model_id' => null, 'stage_id' => null,
            'group_label' => 'Example group', 'assigned_user_id' => null, 'assigned_tech_id' => null, 'storage_location_id' => null,
            'target_deploy_date' => '2026-08-15', 'deployed_at' => '2026-08-16 11:15:00', 'notes' => null,
            'created_at' => '2026-07-01 00:00:00'], $rows[0]);
        $this->rows($reader, 'deployment-waves', 1);
    }

    public function test_maintenances_type_booleans_ints_and_costs(): void
    {
        $asset = Asset::factory()->create(['asset_tag' => 'EXAMPLE-3']);
        $kept = DB::table('maintenances')->insertGetId(['asset_id' => $asset->getKey(), 'asset_maintenance_type' => 'Repair',
            'name' => 'Example repair', 'is_warranty' => 1, 'start_date' => '2026-02-01', 'completion_date' => '2026-02-03',
            'asset_maintenance_time' => 2, 'cost' => '149.50', 'created_at' => '2026-02-01 12:00:00']);
        DB::table('maintenances')->insert(['asset_id' => $asset->getKey(), 'asset_maintenance_type' => 'Repair', 'name' => 'Gone',
            'is_warranty' => 0, 'start_date' => '2026-02-01', 'deleted_at' => '2026-02-02 00:00:00']);

        $rows = $this->rows(User::factory()->create(['permissions' => '{"assets.view":"1"}']), 'maintenances', 1);

        $this->assertSame(['id' => (string) $kept, 'asset_id' => (string) $asset->getKey(), 'supplier_id' => null,
            'asset_maintenance_type' => 'Repair', 'name' => 'Example repair', 'url' => null, 'is_warranty' => true,
            'start_date' => '2026-02-01', 'completion_date' => '2026-02-03', 'asset_maintenance_time' => 2, 'notes' => null,
            'cost' => '149.50', 'created_by' => null, 'created_at' => '2026-02-01 12:00:00'], $rows[0]);
    }

    public function test_user_access_carries_only_sorted_group_ids_and_own_permissions(): void
    {
        $admin = User::factory()->superuser()->create();
        $member = User::factory()->create(['email' => 'member@example.test', 'permissions' => '{"assets.view":"1"}']);
        $gone = User::factory()->create(['email' => 'gone@example.test']);
        $high = DB::table('permission_groups')->insertGetId(['name' => 'Example high', 'permissions' => '{"admin":"1"}']);
        $low = DB::table('permission_groups')->insertGetId(['name' => 'Example low']);
        // Stored out of order; the observation lists ascending group ids.
        DB::table('users_groups')->insert([['user_id' => $member->id, 'group_id' => $low], ['user_id' => $member->id, 'group_id' => $high]]);
        $gone->delete();

        $rows = collect($this->rows($admin, 'user-access', User::count()))->keyBy('id');

        $this->assertArrayNotHasKey((string) $gone->id, $rows->all());
        $this->assertSame(['id' => (string) $member->id, 'group_ids' => $high.','.$low, 'permissions' => '{"assets.view":"1"}'],
            $rows[(string) $member->id]);
        $this->assertNull($rows[(string) $admin->id]['group_ids']);

        $groups = $this->rows($admin, 'permission-groups', 2);
        $this->assertSame(['id' => (string) $high, 'name' => 'Example high', 'permissions' => '{"admin":"1"}', 'notes' => null,
            'created_at' => null], $groups[0]);
    }

    public function test_action_logs_leave_out_excluded_columns_and_deleted_rows(): void
    {
        $actor = User::factory()->create();
        $kept = DB::table('action_logs')->insertGetId(['created_by' => $actor->id, 'action_type' => 'checkout', 'target_id' => 0,
            'target_type' => 'App\\Models\\User', 'item_type' => 'App\\Models\\Asset', 'item_id' => 7, 'quantity' => 1,
            'log_meta' => '{"name":{"old":"a","new":"b"}}', 'action_date' => '2026-03-01 10:00:00', 'action_source' => 'gui',
            'expected_checkin' => '2026-04-01', 'remote_ip' => '192.0.2.10', 'user_agent' => 'Example', 'filename' => 'example.pdf',
            'company_id' => 1, 'created_at' => '2026-03-01 10:00:01']);
        DB::table('action_logs')->insert(['action_type' => 'delete', 'item_type' => 'App\\Models\\Asset', 'item_id' => 8,
            'deleted_at' => '2026-03-02 00:00:00']);

        $admin = User::factory()->superuser()->create();
        $live = DB::table('action_logs')->whereNull('deleted_at')->count();
        $rows = collect($this->rows($admin, 'action-logs', $live))->keyBy('id');

        $this->assertSame(['id' => (string) $kept, 'created_by' => (string) $actor->id, 'action_type' => 'checkout',
            'target_id' => null, 'target_type' => 'App\\Models\\User', 'location_id' => null, 'note' => null,
            'item_type' => 'App\\Models\\Asset', 'item_id' => '7', 'expected_checkin' => '2026-04-01', 'quantity' => 1,
            'log_meta' => '{"name":{"old":"a","new":"b"}}', 'action_date' => '2026-03-01 10:00:00', 'action_source' => 'gui',
            'created_at' => '2026-03-01 10:00:01'], $rows[(string) $kept]);
        $this->assertSame($live, $rows->count());
        $this->actingAsForApi($admin)
            ->getJson(self::BASE.'action-logs?offset=1000000')->assertOk();
    }

    public function test_admin_only_families_require_a_superuser_and_unknown_families_404(): void
    {
        $admin = User::factory()->create(['permissions' => '{"admin":"1"}']);
        foreach (['permission-groups', 'user-access', 'form-eligibility', 'action-logs'] as $family) {
            $this->actingAsForApi($admin)->getJson(self::BASE.$family)->assertForbidden();
        }
        $this->actingAsForApi($admin)->getJson(self::BASE.'deployment-types')->assertOk();
        $this->actingAsForApi(User::factory()->create(['permissions' => '{"assets.view":"1"}']))
            ->getJson(self::BASE.'deployment-types')->assertForbidden();
        foreach (['users', 'password-resets', 'oauth-clients'] as $family) {
            $this->actingAsForApi(User::factory()->superuser()->create())->getJson(self::BASE.$family)->assertNotFound();
        }
    }

    public function test_rows_page_in_id_order_within_bounds(): void
    {
        $ids = [];
        foreach (['charlie', 'alpha', 'bravo'] as $order => $need) {
            $ids[] = (string) DB::table('capital_request_lines')->insertGetId(['fiscal_year' => 'FY27', 'need' => 'Example '.$need,
                'quantity' => 3 - $order, 'unit_cost' => '0.10', 'sort_order' => $order]);
        }
        $this->actingAsForApi(User::factory()->create(['permissions' => '{"procurement.view":"1"}']));

        $first = $this->getJson(self::BASE.'capital-request-lines?limit=2')->assertOk()->assertJsonPath('total', 3)->json('rows');
        $second = $this->getJson(self::BASE.'capital-request-lines?limit=2&offset=2')->assertOk()->json('rows');
        $this->assertSame($ids, array_column([...$first, ...$second], 'id'));
        $this->assertSame(3, $first[0]['quantity']);
        $this->assertSame('0.10', $first[0]['unit_cost']);
        $this->getJson(self::BASE.'capital-request-lines?limit=101')->assertStatus(422);
        $this->getJson(self::BASE.'capital-request-lines?offset=100001')->assertStatus(422);
    }

    /** Each child family: its route, the read permission, and how to write a parent and a child. */
    public static function childFamilies(): array
    {
        return [
            'deployment items' => ['deployment-items', 'deployments.view', 'deployment_waves', 'deployment_items', 'wave_id'],
            'buyout quotes' => ['asset-buyout-quotes', 'deployments.view', 'asset_buyouts', 'asset_buyout_quotes', 'asset_buyout_id'],
            'pickup assets' => ['lease-pickup-assets', 'deployments.view', 'lease_pickups', 'lease_pickup_assets', 'lease_pickup_id'],
            'store order items' => ['store-order-items', 'orders.view', 'store_orders', 'store_order_items', 'store_order_id'],
        ];
    }

    #[DataProvider('childFamilies')]
    public function test_children_of_parents_out_of_the_readers_scope_are_not_returned(
        string $family, string $permission, string $parentTable, string $childTable, string $parentColumn
    ): void {
        $this->settings->enableMultipleFullCompanySupport();
        $own = Company::factory()->create();
        $other = Company::factory()->create();
        $asset = Asset::factory()->create(['company_id' => $own->getKey(), 'asset_tag' => 'EXAMPLE-OWN']);
        $foreign = Asset::factory()->create(['company_id' => $other->getKey(), 'asset_tag' => 'EXAMPLE-FOREIGN']);
        $parent = fn (array $values = []) => DB::table($parentTable)->insertGetId($values + match ($parentTable) {
            'deployment_waves' => ['name' => 'Example wave', 'slug' => 'example-'.uniqid()],
            'asset_buyouts' => ['asset_id' => $asset->getKey(), 'status' => 'requested'],
            'lease_pickups' => ['status' => 'requested'],
            'store_orders' => ['status' => 'submitted'],
        });
        $child = fn (int $parentId) => DB::table($childTable)->insertGetId([$parentColumn => $parentId]
            + ($childTable === 'lease_pickup_assets' ? ['asset_id' => $asset->getKey()] : []));

        $visible = $child($parent());
        $hidden = [];
        if ($parentTable !== 'store_orders') {
            $hidden[] = $child($parent(['deleted_at' => '2026-01-01 00:00:00']));
        }
        if ($parentTable === 'asset_buyouts') {
            // The parent's own asset is in another company, so neither it nor its quotes show.
            $hidden[] = $child($parent(['asset_id' => $foreign->getKey()]));
        }
        // A child whose parent row no longer exists has nothing to inherit visibility from.
        $hidden[] = $child(999999);

        $reader = User::factory()->create(['company_id' => $own->getKey(), 'permissions' => json_encode([$permission => '1'])]);
        $rows = $this->rows($reader, $family, 1);

        $this->assertSame([(string) $visible], array_column($rows, 'id'));
        foreach ($hidden as $id) {
            $this->assertNotContains((string) $id, array_column($rows, 'id'));
        }
    }
}
