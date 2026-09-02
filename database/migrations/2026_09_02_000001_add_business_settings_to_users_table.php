<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('business_name')->nullable()->after('name');
            $table->string('phone', 30)->nullable()->after('business_name');
            $table->string('gst_number', 30)->nullable()->after('phone');
            $table->boolean('gst_registered')->default(true)->after('gst_number');
            $table->decimal('gst_rate', 5, 2)->default(5.00)->after('gst_registered');
            $table->json('payment_methods')->nullable()->after('gst_rate');
            $table->string('etransfer_email')->nullable()->after('payment_methods');
            $table->text('booking_instructions')->nullable()->after('etransfer_email');
            $table->string('timezone', 64)->default('America/Vancouver')->after('booking_instructions');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'business_name',
                'phone',
                'gst_number',
                'gst_registered',
                'gst_rate',
                'payment_methods',
                'etransfer_email',
                'booking_instructions',
                'timezone',
            ]);
        });
    }
};
