<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_attendee_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_attendee_id')->constrained()->cascadeOnDelete();
            // Restricted, not cascaded: deleting a member must never quietly rewrite the
            // headcount of a session that has already been charged.
            $table->foreignId('family_member_id')->constrained()->restrictOnDelete();
            $table->string('member_name', 100); // snapshot, so old receipts read as they did
            $table->boolean('attended')->default(true);
            $table->decimal('price_override', 10, 2)->nullable(); // before GST
            $table->decimal('subtotal', 10, 2)->default(0); // before GST; GST is worked out on the row total
            $table->timestamps();

            // Named explicitly: the generated name would exceed MySQL's 64-character limit.
            $table->unique(['session_attendee_id', 'family_member_id'], 'attendee_member_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_attendee_members');
    }
};
