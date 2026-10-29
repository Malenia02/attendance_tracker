<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_correction_requests', function (Blueprint $table): void {
            // Widen the legacy enum to a string so adding a workflow state does
            // not require a dialect-specific enum alteration or rewrite rows.
            $table->string('request_status', 20)->default('Pending')->change();
        });
    }

    public function down(): void
    {
        if (DB::table('attendance_correction_requests')->where('request_status', 'Resolved')->exists()) {
            throw new RuntimeException(
                'Cannot roll back while resolved correction requests exist; preserve them or apply a forward migration.'
            );
        }

        Schema::table('attendance_correction_requests', function (Blueprint $table): void {
            $table->enum('request_status', ['Pending', 'Approved', 'Rejected', 'Cancelled'])
                ->default('Pending')
                ->change();
        });
    }
};
