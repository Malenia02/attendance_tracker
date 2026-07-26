<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dtr_status_logs', function (Blueprint $table): void {
            $table->bigIncrements('dtr_status_log_id');
            $table->unsignedBigInteger('dtr_certification_id');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->string('remarks', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('dtr_certification_id')
                ->references('dtr_certification_id')
                ->on('dtr_certifications')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreign('changed_by')
                ->references('user_id')
                ->on('system_users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->index(['dtr_certification_id', 'created_at'], 'idx_dtr_status_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dtr_status_logs');
    }
};
