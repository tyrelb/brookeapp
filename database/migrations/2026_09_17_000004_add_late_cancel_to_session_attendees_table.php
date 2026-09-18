<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_attendees', function (Blueprint $table) {
            // Narrows `attended` rather than replacing it: a late cancel is still on the bill
            // (attended = true), it just wasn't in the room, so the gym does not charge for it.
            $table->boolean('late_cancelled')->default(false)->after('attended');
            // Shown to the client on their receipt, ledger line and training history. Unlike
            // the session's notes, which stay private to the trainer.
            $table->string('client_note', 150)->nullable()->after('late_cancelled');
        });
    }

    public function down(): void
    {
        Schema::table('session_attendees', function (Blueprint $table) {
            $table->dropColumn(['late_cancelled', 'client_note']);
        });
    }
};
