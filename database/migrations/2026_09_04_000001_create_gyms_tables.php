<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gyms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('billing_model', 30); // App\Enums\GymBillingModel
            $table->decimal('monthly_fee', 10, 2)->nullable();
            $table->json('usage_rates')->nullable(); // {"1": 18, "2": 26, ... "10": 70} per session, before GST
            $table->boolean('charges_gst')->default(true);
            $table->decimal('gst_rate', 5, 2)->default(5.00);
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('gym_usage_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gym_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM
            $table->dateTime('finalized_at');
            $table->json('snapshot'); // summary + rows as they were when finalized
            $table->timestamps();

            $table->unique(['gym_id', 'period']);
        });

        Schema::table('training_sessions', function (Blueprint $table) {
            $table->foreignId('gym_id')->nullable()->after('service_id')->constrained()->nullOnDelete();
            $table->boolean('gym_billable')->default(true)->after('gym_id'); // untick to ignore on the gym report
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gym_id');
            $table->dropColumn('gym_billable');
        });
        Schema::dropIfExists('gym_usage_reports');
        Schema::dropIfExists('gyms');
    }
};
