<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record of every email Snipe sends: who it went to, the subject, when,
 * and which records it was about. Mail goes out through a relay with no
 * mailbox behind it, so without this there is no copy anywhere to answer
 * "did they get the email?". Rows can also be imported from the mail
 * system's own delivery trace for mail sent before this table existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_logs')) {
            Schema::create('email_logs', function (Blueprint $table) {
                $table->id();
                $table->string('message_id', 191)->nullable()->unique();
                $table->string('mailable', 191)->nullable();
                $table->string('subject', 500)->nullable();
                $table->string('from_address', 191)->nullable();
                $table->json('to_addresses')->nullable();
                $table->json('cc_addresses')->nullable();
                $table->json('bcc_addresses')->nullable();
                $table->string('source', 32)->default('snipe');
                $table->string('status', 64)->nullable();
                $table->timestamp('sent_at')->nullable()->index();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('email_log_links')) {
            Schema::create('email_log_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('email_log_id')->constrained('email_logs')->cascadeOnDelete();
                $table->string('linkable_type', 191);
                $table->unsignedBigInteger('linkable_id');
                $table->unique(['email_log_id', 'linkable_type', 'linkable_id'], 'email_log_links_unique');
                $table->index(['linkable_type', 'linkable_id'], 'email_log_links_linkable');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_log_links');
        Schema::dropIfExists('email_logs');
    }
};
