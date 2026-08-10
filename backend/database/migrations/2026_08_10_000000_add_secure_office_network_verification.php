<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_networks', function (Blueprint $table): void {
            $table->id('office_network_id');
            $table->unsignedInteger('department_id');
            $table->string('network_name', 80);
            $table->string('ip_address', 45);
            $table->dateTime('verified_at');
            $table->dateTime('expires_at');
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['department_id', 'ip_address'], 'uq_office_network_department_ip');
            $table->index(['ip_address', 'status', 'expires_at'], 'idx_office_network_lookup');
            $table->foreign('department_id', 'fk_office_network_department')
                ->references('department_id')->on('departments')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('created_by', 'fk_office_network_creator')
                ->references('user_id')->on('system_users')
                ->nullOnDelete()->cascadeOnUpdate();
        });

        Schema::table('qr_scan_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('office_network_id')->nullable()->after('distance_from_office_meters');
            $table->string('location_verification_method', 32)->nullable()->after('office_network_id');
            $table->index(['office_network_id', 'scanned_at'], 'idx_qr_scan_network_datetime');
            $table->foreign('office_network_id', 'fk_qr_scan_office_network')
                ->references('office_network_id')->on('office_networks')
                ->nullOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('qr_scan_logs', function (Blueprint $table): void {
            $table->dropForeign('fk_qr_scan_office_network');
            $table->dropIndex('idx_qr_scan_network_datetime');
            $table->dropColumn(['office_network_id', 'location_verification_method']);
        });

        Schema::dropIfExists('office_networks');
    }
};
