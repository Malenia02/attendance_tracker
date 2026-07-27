<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE dtr_certifications MODIFY certification_status
                ENUM('Draft','Submitted','Certified','Returned','Reopened')
                NOT NULL DEFAULT 'Draft'"
            );
        }

        Schema::table('dtr_certifications', function (Blueprint $table): void {
            $table->unsignedSmallInteger('version_number')->default(1)->after('dtr_month');
        });

        Schema::create('dtr_certification_versions', function (Blueprint $table): void {
            $table->bigIncrements('dtr_certification_version_id');
            $table->unsignedBigInteger('dtr_certification_id');
            $table->unsignedSmallInteger('version_number');
            $table->unsignedBigInteger('prepared_by')->nullable();
            $table->unsignedBigInteger('certified_by')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->dateTime('prepared_at')->nullable();
            $table->dateTime('certified_at');
            $table->dateTime('archived_at');
            $table->string('archive_reason', 1000);
            $table->json('certified_snapshot');
            $table->char('certified_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('dtr_certification_id', 'fk_dtr_version_certification')
                ->references('dtr_certification_id')
                ->on('dtr_certifications')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreign('prepared_by', 'fk_dtr_version_prepared_by')
                ->references('user_id')->on('system_users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('certified_by', 'fk_dtr_version_certified_by')
                ->references('user_id')->on('system_users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('archived_by', 'fk_dtr_version_archived_by')
                ->references('user_id')->on('system_users')->nullOnDelete()->cascadeOnUpdate();
            $table->unique(
                ['dtr_certification_id', 'version_number'],
                'uq_dtr_certification_version'
            );
        });

        Schema::create('dtr_reopen_requests', function (Blueprint $table): void {
            $table->bigIncrements('dtr_reopen_request_id');
            $table->unsignedBigInteger('dtr_certification_id');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('reason', 1000);
            $table->json('affected_dates');
            $table->enum('request_status', ['Pending', 'Approved', 'Rejected'])->default('Pending');
            $table->string('review_remarks', 1000)->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('pending_key', 64)->nullable()->unique();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->uuid('request_id')->unique();
            $table->timestamps();

            $table->foreign('dtr_certification_id', 'fk_dtr_reopen_certification')
                ->references('dtr_certification_id')
                ->on('dtr_certifications')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreign('requested_by', 'fk_dtr_reopen_requested_by')
                ->references('user_id')->on('system_users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('reviewed_by', 'fk_dtr_reopen_reviewed_by')
                ->references('user_id')->on('system_users')->nullOnDelete()->cascadeOnUpdate();
            $table->index(
                ['dtr_certification_id', 'request_status', 'created_at'],
                'idx_dtr_reopen_history'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dtr_reopen_requests');
        Schema::dropIfExists('dtr_certification_versions');

        Schema::table('dtr_certifications', function (Blueprint $table): void {
            $table->dropColumn('version_number');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE dtr_certifications MODIFY certification_status
                ENUM('Draft','Submitted','Certified','Returned')
                NOT NULL DEFAULT 'Draft'"
            );
        }
    }
};
