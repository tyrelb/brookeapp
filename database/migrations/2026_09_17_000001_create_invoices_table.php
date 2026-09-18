<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence'); // per-trainer counter behind the number
            $table->string('number', 20);        // INV-0042
            $table->json('lines');               // frozen [{description, quantity, unit_price, amount}]
            $table->decimal('subtotal', 10, 2);  // before GST
            $table->decimal('gst_amount', 10, 2)->default(0);
            $table->decimal('gst_rate', 5, 2)->default(0); // frozen at issue, like the gym cover rate
            $table->decimal('total', 10, 2);
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->text('message')->nullable(); // optional note from the trainer
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'number']);
            $table->unique(['user_id', 'sequence']);
            $table->index(['client_id', 'issued_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
