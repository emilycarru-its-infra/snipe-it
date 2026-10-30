<?php

namespace Tests\Feature\ProductIdentities;

use App\Models\ProductIdentity;
use Tests\TestCase;

class ProductIdentityCommandsTest extends TestCase
{
    private function csv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pid').'.csv';
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_import_is_a_dry_run_unless_applied_and_is_idempotent()
    {
        $file = $this->csv("Name,Publisher,Procurement_Name,Platform,Match_Type,Pattern\n"
            ."KeyShot,Luxion,Keyshot,any,exact,KeyShot\n"
            ."KeyShot,Luxion,Keyshot,macos,prefix,com.luxion.keyshot\n"
            ."Pro Tools,Avid,Protools,,,\n"
            ."Broken,,,any,regex,(unclosed\n");

        $this->artisan('product-identities:import', ['file' => $file])->assertExitCode(0);
        $this->assertSame(0, ProductIdentity::count());

        $this->artisan('product-identities:import', ['file' => $file, '--apply' => true])->assertExitCode(0);
        $this->assertSame(['Broken', 'KeyShot', 'Pro Tools'], ProductIdentity::orderBy('name')->pluck('name')->all());
        $keyshot = ProductIdentity::where('name', 'KeyShot')->first();
        $this->assertSame('Keyshot', $keyshot->procurement_name);
        $this->assertCount(2, $keyshot->aliases);
        $this->assertCount(0, ProductIdentity::where('name', 'Broken')->first()->aliases);

        $this->artisan('product-identities:import', ['file' => $file, '--apply' => true])
            ->expectsOutputToContain('0 product(s) to create, 0 alias(es) to add')
            ->assertExitCode(0);
    }

    public function test_unmapped_reports_high_usage_applications_nothing_claims()
    {
        ProductIdentity::factory()->withAliases([['any', 'prefix', 'Maya']])->create(['name' => 'Autodesk Maya']);
        $file = $this->csv("application,platform,hours,devices\n"
            ."Maya 2025,windows,30,10\n"
            ."Houdini FX,windows,40,6\n"
            ."Calculator,windows,0.2,50\n");

        $this->artisan('product-identities:unmapped', ['file' => $file, '--min-usage' => 1])
            ->expectsOutputToContain('1 product(s) resolved, 1 unmapped application(s) at or above the usage floor, 1 below it.')
            ->expectsOutputToContain('Autodesk Maya')
            ->assertExitCode(0);
    }

    public function test_unmapped_refuses_a_file_without_a_name_column()
    {
        $this->artisan('product-identities:unmapped', ['file' => $this->csv("foo,bar\n1,2\n")])->assertExitCode(1);
    }
}
