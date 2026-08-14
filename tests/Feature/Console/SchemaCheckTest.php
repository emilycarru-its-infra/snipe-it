<?php

namespace Tests\Feature\Console;

use App\Console\Commands\SchemaCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaCheckTest extends TestCase
{
    /**
     * `Schema::getTables()` is not scoped to the connection's schema. On MySQL
     * it returns every table the server can see, so on a shared server a table
     * belonging to somebody else's database was recorded in our snapshot with
     * an empty column list — silently, with no error, into a committed file.
     */
    public function test_the_table_list_ignores_tables_in_other_databases()
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Cross-database visibility is a MySQL-specific concern.');
        }

        $ours = $this->baseTables();
        $this->assertNotEmpty($ours, 'the check should see our own tables');

        $foreign = 'snipeit_schemacheck_probe';
        DB::statement("CREATE DATABASE IF NOT EXISTS `{$foreign}`");

        try {
            DB::statement("CREATE TABLE IF NOT EXISTS `{$foreign}`.`a_table_that_is_not_ours` (id INT)");

            $this->assertNotContains(
                'a_table_that_is_not_ours',
                $this->baseTables(),
                'a table in another database must not be counted as one of ours'
            );
            $this->assertEquals($ours, $this->baseTables(), 'the list should be unchanged by a foreign table');
        } finally {
            DB::statement("DROP DATABASE IF EXISTS `{$foreign}`");
        }
    }

    public function test_views_are_excluded()
    {
        $this->assertNotContains('', $this->baseTables());
        foreach (Schema::getTables() as $table) {
            if (($table['type'] ?? 'table') === 'view') {
                $this->assertNotContains($table['name'], $this->baseTables());
            }
        }
    }

    /** @return string[] */
    private function baseTables(): array
    {
        $command = app(SchemaCheck::class);
        $method = new \ReflectionMethod($command, 'baseTables');

        return $method->invoke($command);
    }
}
