<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('personnel')
            ->where(function ($query): void {
                $query->whereNull('qr_login_code')
                    ->orWhere('qr_login_code', '');
            })
            ->select('personnel_id')
            ->orderBy('personnel_id')
            ->chunkById(100, function ($personnel): void {
                foreach ($personnel as $person) {
                    DB::table('personnel')
                        ->where('personnel_id', $person->personnel_id)
                        ->update(['qr_login_code' => Str::random(64)]);
                }
            }, 'personnel_id');
    }

    public function down(): void
    {
        // QR credentials are intentionally retained to avoid revoking printed cards.
    }
};
