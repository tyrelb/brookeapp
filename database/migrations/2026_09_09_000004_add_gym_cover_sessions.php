<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gyms', function (Blueprint $table) {
            // {"1": 50, "2": 70} — what the GYM PAYS the trainer per session for covering
            // its own clients, before the TRAINER's GST. Independent of billing_model:
            // a rent-only gym can still pay for cover.
            $table->json('cover_rates')->nullable()->after('usage_rates');
        });

        Schema::table('training_sessions', function (Blueprint $table) {
            $table->boolean('gym_cover')->default(false)->after('gym_billable');
            $table->json('cover_names')->nullable()->after('gym_cover'); // free text; the gym's own clients
            // Priced once at completion and never re-derived, so that editing a rate card
            // or changing GST registration cannot rewrite a month already reported on.
            // Null means "never priced"; 0.00 means "priced at zero".
            $table->decimal('cover_subtotal', 10, 2)->nullable()->after('cover_names');
            $table->decimal('cover_gst_amount', 10, 2)->nullable()->after('cover_subtotal');
            $table->decimal('cover_gst_rate', 5, 2)->nullable()->after('cover_gst_amount');

            $table->index(['gym_id', 'gym_cover', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropIndex(['gym_id', 'gym_cover', 'starts_at']);
            $table->dropColumn(['gym_cover', 'cover_names', 'cover_subtotal', 'cover_gst_amount', 'cover_gst_rate']);
        });

        Schema::table('gyms', function (Blueprint $table) {
            $table->dropColumn('cover_rates');
        });
    }
};
