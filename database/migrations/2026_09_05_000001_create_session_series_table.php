<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('gym_id')->nullable()->constrained()->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on'); // always required: nothing repeats forever
            $table->string('time', 5); // HH:MM
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedTinyInteger('interval_weeks')->default(1); // 1, 2 or 4
            $table->json('weekdays'); // ISO weekday numbers, 1 = Monday … 7 = Sunday
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'starts_on']);
        });

        Schema::table('training_sessions', function (Blueprint $table) {
            $table->foreignId('session_series_id')->nullable()->after('gym_billable')->constrained('session_series')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('session_series_id');
        });
        Schema::dropIfExists('session_series');
    }
};
