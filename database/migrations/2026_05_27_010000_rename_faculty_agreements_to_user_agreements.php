<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('faculty_agreements') && ! Schema::hasTable('user_agreements')) {
            Schema::rename('faculty_agreements', 'user_agreements');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('user_agreements') && ! Schema::hasTable('faculty_agreements')) {
            Schema::rename('user_agreements', 'faculty_agreements');
        }
    }
};
