<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_issue_reports', function (Blueprint $table): void {
            $table->id('issue_report_id');
            $table->unsignedBigInteger('reported_by')->nullable();
            $table->string('reporter_name', 160);
            $table->string('category', 40);
            $table->string('subject', 120);
            $table->text('description');
            $table->string('page_url', 500)->nullable();
            $table->string('status', 20)->default('Open');
            $table->text('admin_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('reported_by')
                ->references('user_id')
                ->on('system_users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->foreign('reviewed_by')
                ->references('user_id')
                ->on('system_users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->index(['status', 'created_at'], 'issue_reports_status_created_idx');
            $table->index(['reported_by', 'created_at'], 'issue_reports_reporter_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_issue_reports');
    }
};
