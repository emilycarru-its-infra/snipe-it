<?php

namespace Tests\Feature\Contracts\Api;

use App\Models\Asset;
use App\Models\Company;
use App\Models\Statuslabel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogRecordObservationsTest extends TestCase
{
    private const BASE = '/api/v1/workflow-observations/';

    private function rows(User $user, string $family, int $total): array
    {
        return $this->actingAsForApi($user)->getJson(self::BASE.$family)
            ->assertOk()->assertJsonPath('total', $total)->json('rows');
    }

    public function test_suppliers_return_their_stored_columns_and_deleted_rows(): void
    {
        $author = User::factory()->create();
        $kept = DB::table('suppliers')->insertGetId(['name' => 'Example Supply', 'city' => 'Example City', 'email' => 'orders@example.test',
            'order_emails' => 'orders@example.test', 'contract_prefixes' => 'EX-', 'colleague_vendor_id' => 'V0001',
            'created_by' => $author->id, 'created_at' => '2026-01-05 09:00:00']);
        $gone = DB::table('suppliers')->insertGetId(['name' => 'Retired Supply', 'deleted_at' => '2026-02-01 00:00:00']);

        $all = DB::table('suppliers')->count();

        $rows = collect($this->rows(User::factory()->create(['permissions' => '{"suppliers.view":"1"}']), 'suppliers', $all))->keyBy('id');

        $this->assertSame(['id' => (string) $kept, 'name' => 'Example Supply', 'address' => null, 'address2' => null,
            'city' => 'Example City', 'state' => null, 'country' => null, 'zip' => null, 'contact' => null, 'phone' => null,
            'fax' => null, 'email' => 'orders@example.test', 'url' => null, 'notes' => null, 'image' => null, 'tag_color' => null,
            'order_emails' => 'orders@example.test', 'lease_emails' => null, 'pickup_emails' => null, 'contract_prefixes' => 'EX-',
            'colleague_vendor_id' => 'V0001', 'created_by' => (string) $author->id, 'created_at' => '2026-01-05 09:00:00',
            'deleted_at' => null], $rows[(string) $kept]);
        $this->assertSame('Retired Supply', $rows[(string) $gone]['name']);
        $this->assertSame('2026-02-01 00:00:00', $rows[(string) $gone]['deleted_at']);
    }

    public function test_status_labels_type_their_flags_and_keep_deleted_labels(): void
    {
        $kept = DB::table('status_labels')->insertGetId(['name' => 'Example Ready', 'deployable' => 1, 'pending' => 0, 'archived' => 0,
            'notes' => 'Example note', 'color' => '#00ff00', 'show_in_nav' => 1, 'default_label' => 1, 'created_at' => '2026-01-01 08:00:00']);
        $gone = DB::table('status_labels')->insertGetId(['name' => 'Example Retired', 'deployable' => 0, 'pending' => 0, 'archived' => 1,
            'deleted_at' => '2026-03-01 00:00:00']);
        $all = DB::table('status_labels')->count();

        $rows = collect($this->rows(User::factory()->create(['permissions' => '{"statuslabels.view":"1"}']), 'status-labels', $all))->keyBy('id');

        $this->assertSame(['id' => (string) $kept, 'name' => 'Example Ready', 'deployable' => true, 'pending' => false, 'archived' => false,
            'notes' => 'Example note', 'color' => '#00ff00', 'show_in_nav' => true, 'default_label' => true, 'created_by' => null,
            'created_at' => '2026-01-01 08:00:00', 'deleted_at' => null], $rows[(string) $kept]);
        $this->assertTrue($rows[(string) $gone]['archived']);
        $this->assertSame('2026-03-01 00:00:00', $rows[(string) $gone]['deleted_at']);
    }

    public function test_categories_return_type_flags_and_eula_and_keep_deleted_rows(): void
    {
        $kept = DB::table('categories')->insertGetId(['name' => 'Example Laptops', 'category_type' => 'asset', 'require_acceptance' => 1,
            'checkin_email' => 0, 'use_default_eula' => 0, 'alert_on_response' => 1, 'eula_text' => 'Example terms',
            'created_at' => '2026-01-02 10:00:00']);
        $gone = DB::table('categories')->insertGetId(['name' => 'Example Old', 'category_type' => 'license', 'deleted_at' => '2026-04-01 00:00:00']);
        $all = DB::table('categories')->count();

        $rows = collect($this->rows(User::factory()->create(['permissions' => '{"categories.view":"1"}']), 'categories', $all))->keyBy('id');

        $this->assertSame(['id' => (string) $kept, 'name' => 'Example Laptops', 'category_type' => 'asset', 'require_acceptance' => true,
            'checkin_email' => false, 'use_default_eula' => false, 'alert_on_response' => true, 'eula_text' => 'Example terms',
            'notes' => null, 'image' => null, 'tag_color' => null, 'created_by' => null, 'created_at' => '2026-01-02 10:00:00',
            'deleted_at' => null], $rows[(string) $kept]);
        $this->assertSame('license', $rows[(string) $gone]['category_type']);
        $this->assertSame('2026-04-01 00:00:00', $rows[(string) $gone]['deleted_at']);
    }

    public function test_departments_keep_deleted_rows_inside_the_company_scope(): void
    {
        $this->settings->enableMultipleFullCompanySupport();
        $own = Company::factory()->create();
        $other = Company::factory()->create();
        $manager = User::factory()->create(['company_id' => $own->getKey()]);
        $kept = DB::table('departments')->insertGetId(['name' => 'Example Studio', 'company_id' => $own->getKey(),
            'manager_id' => $manager->id, 'notes' => 'Example note', 'procurement_access' => 1, 'created_at' => '2026-01-03 00:00:00']);
        $gone = DB::table('departments')->insertGetId(['name' => 'Example Closed', 'company_id' => $own->getKey(),
            'deleted_at' => '2026-05-01 00:00:00']);
        $foreign = DB::table('departments')->insertGetId(['name' => 'Example Foreign', 'company_id' => $other->getKey()]);
        $foreignGone = DB::table('departments')->insertGetId(['name' => 'Example Foreign Closed', 'company_id' => $other->getKey(),
            'deleted_at' => '2026-05-01 00:00:00']);

        $reader = User::factory()->create(['company_id' => $own->getKey(), 'permissions' => '{"departments.view":"1"}']);
        $rows = $this->rows($reader, 'departments', 2);

        $this->assertSame([(string) $kept, (string) $gone], array_column($rows, 'id'));
        $this->assertSame(['id' => (string) $kept, 'name' => 'Example Studio', 'company_id' => (string) $own->getKey(),
            'location_id' => null, 'manager_id' => (string) $manager->id, 'phone' => null, 'fax' => null, 'notes' => 'Example note',
            'procurement_access' => true, 'image' => null, 'tag_color' => null, 'created_by' => null,
            'created_at' => '2026-01-03 00:00:00', 'deleted_at' => null], $rows[0]);
        $this->assertSame('2026-05-01 00:00:00', $rows[1]['deleted_at']);

        $all = array_column($this->rows(User::factory()->superuser()->create(), 'departments', 4), 'id');
        $this->assertContains((string) $foreign, $all);
        $this->assertContains((string) $foreignGone, $all);
    }

    public function test_asset_statuses_keep_labels_that_were_soft_deleted_and_the_company_scope(): void
    {
        $this->settings->enableMultipleFullCompanySupport();
        $own = Company::factory()->create();
        $other = Company::factory()->create();
        $live = Statuslabel::factory()->create(['name' => 'Example Live']);
        $retired = Statuslabel::factory()->create(['name' => 'Example Retired']);
        $asset = Asset::factory()->create(['company_id' => $own->getKey(), 'status_id' => $live->id, 'asset_tag' => 'EXAMPLE-S1']);
        $orphan = Asset::factory()->create(['company_id' => $own->getKey(), 'status_id' => $retired->id, 'asset_tag' => 'EXAMPLE-S2']);
        $deleted = Asset::factory()->create(['company_id' => $own->getKey(), 'status_id' => $live->id, 'asset_tag' => 'EXAMPLE-S3']);
        $foreign = Asset::factory()->create(['company_id' => $other->getKey(), 'status_id' => $live->id, 'asset_tag' => 'EXAMPLE-S4']);
        $retired->delete();
        $deleted->delete();

        $reader = User::factory()->create(['company_id' => $own->getKey(), 'permissions' => '{"assets.view":"1"}']);
        $rows = $this->rows($reader, 'asset-statuses', 3);

        $this->assertSame(['id' => (string) $asset->id, 'status_id' => (string) $live->id, 'deleted_at' => null], $rows[0]);
        $this->assertSame(['id' => (string) $orphan->id, 'status_id' => (string) $retired->id, 'deleted_at' => null], $rows[1]);
        $this->assertSame((string) $deleted->id, $rows[2]['id']);
        $this->assertNotNull($rows[2]['deleted_at']);
        $this->assertNotContains((string) $foreign->id, array_column($rows, 'id'));

        $labels = collect($this->rows(User::factory()->create(['permissions' => '{"statuslabels.view":"1"}']), 'status-labels',
            DB::table('status_labels')->count()))->keyBy('id');
        $this->assertNotNull($labels[(string) $retired->id]['deleted_at']);
    }

    public function test_uploads_return_metadata_only_including_deleted_uploads(): void
    {
        $actor = User::factory()->create();
        $kept = DB::table('action_logs')->insertGetId(['created_by' => $actor->id, 'action_type' => 'uploaded',
            'item_type' => 'App\\Models\\Asset', 'item_id' => 7, 'filename' => 'example-invoice.pdf', 'note' => 'Example note',
            'remote_ip' => '192.0.2.10', 'user_agent' => 'Example', 'created_at' => '2026-03-01 10:00:00']);
        $gone = DB::table('action_logs')->insertGetId(['action_type' => 'uploaded', 'item_type' => 'App\\Models\\User', 'item_id' => 9,
            'filename' => 'example-removed.pdf', 'created_at' => '2026-03-02 10:00:00', 'deleted_at' => '2026-03-03 00:00:00']);
        DB::table('action_logs')->insert(['action_type' => 'checkout', 'item_type' => 'App\\Models\\Asset', 'item_id' => 7,
            'filename' => 'example-signature.png']);

        $rows = $this->rows(User::factory()->superuser()->create(), 'uploads', 2);

        $this->assertSame(['id' => (string) $kept, 'item_type' => 'App\\Models\\Asset', 'item_id' => '7',
            'filename' => 'example-invoice.pdf', 'note' => 'Example note', 'created_by' => (string) $actor->id,
            'created_at' => '2026-03-01 10:00:00', 'deleted_at' => null], $rows[0]);
        $this->assertSame(['id' => (string) $gone, 'item_type' => 'App\\Models\\User', 'item_id' => '9',
            'filename' => 'example-removed.pdf', 'note' => null, 'created_by' => null,
            'created_at' => '2026-03-02 10:00:00', 'deleted_at' => '2026-03-03 00:00:00'], $rows[1]);
    }

    /** Each new family, and the permission that already shows that data in the app. */
    public static function families(): array
    {
        return [
            'suppliers' => ['suppliers', 'suppliers.view'],
            'status labels' => ['status-labels', 'statuslabels.view'],
            'categories' => ['categories', 'categories.view'],
            'departments' => ['departments', 'departments.view'],
            'asset statuses' => ['asset-statuses', 'assets.view'],
        ];
    }

    #[DataProvider('families')]
    public function test_each_family_needs_its_read_permission(string $family, string $permission): void
    {
        $this->actingAsForApi(User::factory()->create(['permissions' => '{"reports.view":"1"}']))
            ->getJson(self::BASE.$family)->assertForbidden();
        $this->actingAsForApi(User::factory()->create(['permissions' => json_encode([$permission => '1'])]))
            ->getJson(self::BASE.$family)->assertOk();
    }

    public function test_uploads_are_superuser_only_and_unknown_families_404(): void
    {
        $this->actingAsForApi(User::factory()->create(['permissions' => '{"admin":"1"}']))
            ->getJson(self::BASE.'uploads')->assertForbidden();
        foreach (['locations-trashed', 'upload-files', 'assets'] as $family) {
            $this->actingAsForApi(User::factory()->superuser()->create())->getJson(self::BASE.$family)->assertNotFound();
        }
    }
}
