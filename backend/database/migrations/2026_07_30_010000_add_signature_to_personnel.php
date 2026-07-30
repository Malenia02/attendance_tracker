<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('personnel', 'signature')) {
            Schema::table('personnel', function (Blueprint $table): void {
                $table->string('signature')->nullable()->after('photo');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('personnel', 'signature')) {
            Schema::table('personnel', function (Blueprint $table): void {
                $table->dropColumn('signature');
            });
        }
    }
};
