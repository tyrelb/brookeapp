<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dateTime('reminded_at')->nullable()->after('sent_at'); // the last reminder
            $table->unsignedSmallInteger('reminder_count')->default(0)->after('reminded_at');
            // Soft, so a deleted invoice keeps its number: it may already be in a client's inbox.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['reminded_at', 'reminder_count']);
        });
    }
};
