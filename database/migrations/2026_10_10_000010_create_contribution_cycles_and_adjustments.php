<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('contribution_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('thrift_group_id')->constrained('thrift_groups')->restrictOnDelete();
            $table->string('name', 160);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('frequency', 20);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedTinyInteger('due_day_of_month')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['thrift_group_id', 'status']);
        });

        Schema::create('contribution_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contribution_schedule_id')->constrained('contribution_schedules')->restrictOnDelete();
            $table->foreignId('thrift_group_id')->constrained('thrift_groups')->restrictOnDelete();
            $table->date('due_on');
            $table->unsignedBigInteger('expected_amount_minor');
            $table->char('currency_code', 3);
            $table->string('status', 20)->default('scheduled');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['contribution_schedule_id', 'due_on']);
            $table->index(['thrift_group_id', 'due_on', 'status']);
        });

        Schema::create('contribution_record_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contribution_record_id')->constrained('contribution_records')->restrictOnDelete();
            $table->foreignId('thrift_group_id')->constrained('thrift_groups')->restrictOnDelete();
            $table->string('adjustment_type', 20);
            $table->unsignedBigInteger('proposed_amount_minor')->nullable();
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();
            $table->index(['thrift_group_id', 'status', 'created_at'], 'cra_group_status_created_idx');
            $table->index(['contribution_record_id', 'created_at'], 'cra_record_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_record_adjustments');
        Schema::dropIfExists('contribution_cycles');
        Schema::dropIfExists('contribution_schedules');
    }
};
