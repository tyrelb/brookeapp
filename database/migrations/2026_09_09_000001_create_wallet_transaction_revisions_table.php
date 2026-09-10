<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transaction_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20); // created | updated | voided
            $table->json('changed_fields')->nullable(); // field => ['from' => ..., 'to' => ...]
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['wallet_transaction_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transaction_revisions');
    }
};
