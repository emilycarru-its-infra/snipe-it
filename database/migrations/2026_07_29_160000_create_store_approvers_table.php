<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may approve or decline store orders. An empty table means the
 * default gate (the orders permission) applies; once anyone is listed,
 * the list is the whole truth — listed users and superusers only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_approvers', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned()->unique();
            $table->integer('created_by')->unsigned()->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_approvers');
    }
};
