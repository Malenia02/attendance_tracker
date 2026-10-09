<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_logs', function (Blueprint $table): void {
            $table->uuid('client_request_id')->nullable()->unique('uq_time_logs_client_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('time_logs', function (Blueprint $table): void {
            $table->dropUnique('uq_time_logs_client_request_id');
            $table->dropColumn('client_request_id');
        });
    }
};
