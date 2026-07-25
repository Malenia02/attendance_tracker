<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_scan_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('scanned_by')->nullable()->after('device_identifier');
            $table->index(['scanned_by', 'scanned_at'], 'idx_qr_scan_operator_datetime');
        });
    }

    public function down(): void
    {
        Schema::table('qr_scan_logs', function (Blueprint $table): void {
            $table->dropIndex('idx_qr_scan_operator_datetime');
            $table->dropColumn('scanned_by');
        });
    }
};
