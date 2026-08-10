<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE dtr_certifications MODIFY certification_status
                ENUM('Draft','Submitted','Submitted Late','Certified','Returned','Reopened')
                NOT NULL DEFAULT 'Draft'"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('dtr_certifications')
                ->where('certification_status', 'Submitted Late')
                ->update(['certification_status' => 'Submitted']);

            DB::statement(
                "ALTER TABLE dtr_certifications MODIFY certification_status
                ENUM('Draft','Submitted','Certified','Returned','Reopened')
                NOT NULL DEFAULT 'Draft'"
            );
        }
    }
};
