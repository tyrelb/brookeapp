<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_on_booking')->default(true)->after('booking_instructions');
            $table->boolean('notify_on_completion')->default(false)->after('notify_on_booking');
        });

        Schema::table('training_sessions', function (Blueprint $table) {
            $table->string('ics_uid', 64)->nullable()->unique()->after('notes'); // stable calendar identity
            $table->unsignedInteger('ics_sequence')->default(0)->after('ics_uid'); // bumped on reschedule
            $table->dateTime('invites_sent_at')->nullable()->after('ics_sequence');
        });

        Schema::table('session_attendees', function (Blueprint $table) {
            $table->dateTime('invite_sent_at')->nullable()->after('wallet_transaction_id');
            $table->dateTime('receipt_sent_at')->nullable()->after('invite_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('session_attendees', fn (Blueprint $t) => $t->dropColumn(['invite_sent_at', 'receipt_sent_at']));
        Schema::table('training_sessions', fn (Blueprint $t) => $t->dropColumn(['ics_uid', 'ics_sequence', 'invites_sent_at']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['notify_on_booking', 'notify_on_completion']));
    }
};
