<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // How many sessions the plan is sold in, when it is sold as a package.
            // Only used to suggest a top-up amount when requesting payment.
            $table->unsignedSmallInteger('package_sessions')->nullable()->after('billing_day');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('package_sessions');
        });
    }
};
