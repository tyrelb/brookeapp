<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20); // App\Enums\PlanType
            $table->decimal('monthly_fee', 10, 2)->nullable();
            $table->unsignedTinyInteger('billing_day')->nullable(); // 1-28
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'active']);
        });

        Schema::create('plan_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('headcount'); // 1 = single, 2 = partner, 3 = triple, 4 = quad
            $table->decimal('unit_price', 10, 2); // per person, before GST
            $table->timestamps();

            $table->unique(['plan_id', 'service_id', 'headcount']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_rates');
        Schema::dropIfExists('plans');
    }
};
