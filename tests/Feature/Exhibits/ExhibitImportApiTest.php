<?php

namespace Tests\Feature\Exhibits;

use App\Models\Exhibit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The token-authed exhibit import writes projects, so it takes the same
 * orders.create gate as the in-app upload. A signed-in user without it is
 * refused before the file is read.
 */
class ExhibitImportApiTest extends TestCase
{
    public function test_a_user_without_orders_create_cannot_import(): void
    {
        $exhibit = Exhibit::firstOrCreate(['name' => 'Grad Show']);
        $file = UploadedFile::fake()->createWithContent('projects.csv', "Name,Email\n");

        $this->actingAsForApi(User::factory()->create())
            ->post(route('api.exhibit.import'), [
                'exhibit_id' => $exhibit->id,
                'year' => 2026,
                'file' => $file,
            ], ['Accept' => 'application/json'])
            ->assertForbidden();
    }
}
