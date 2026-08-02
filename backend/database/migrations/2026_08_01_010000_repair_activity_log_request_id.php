<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        if (! Schema::hasColumn('activity_logs', 'request_id')) {
            Schema::table('activity_logs', function (Blueprint $table): void {
                $table->uuid('request_id')->nullable()->after('user_agent');
            });
        }

        if (! Schema::hasIndex('activity_logs', ['request_id'])) {
            Schema::table('activity_logs', function (Blueprint $table): void {
                $table->index('request_id', 'idx_activity_request_id');
            });
        }
    }

    public function down(): void
    {
        // Forward-only schema repair. The application requires this audit
        // column, and it may be owned by an earlier integrity migration.
    }
};
