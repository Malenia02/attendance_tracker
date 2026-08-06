<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('system_users')
            && ! Schema::hasColumn('system_users', 'remember_token')
        ) {
            Schema::table('system_users', function (Blueprint $table): void {
                $table->string('remember_token', 100)
                    ->nullable()
                    ->after('password_hash');
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('system_users')
            && Schema::hasColumn('system_users', 'remember_token')
        ) {
            Schema::table('system_users', function (Blueprint $table): void {
                $table->dropColumn('remember_token');
            });
        }
    }
};
