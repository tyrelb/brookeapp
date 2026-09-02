<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // App\Enums\TransactionType
            $table->decimal('amount', 10, 2); // signed: credits positive, debits negative
            $table->decimal('subtotal', 10, 2)->default(0); // before GST (unsigned)
            $table->decimal('gst_amount', 10, 2)->default(0); // unsigned
            $table->string('payment_method', 20)->nullable(); // App\Enums\PaymentMethod
            $table->string('reference')->nullable(); // cheque #, e-transfer reference
            $table->date('transacted_on');
            $table->string('billing_period', 7)->nullable(); // YYYY-MM, monthly fees only
            $table->foreignId('training_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'transacted_on']);
            $table->index(['user_id', 'transacted_on']);
            $table->index(['client_id', 'billing_period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
