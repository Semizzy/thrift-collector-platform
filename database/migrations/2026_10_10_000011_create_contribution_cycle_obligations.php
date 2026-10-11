<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('contribution_cycle_obligations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contribution_cycle_id')->constrained('contribution_cycles')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('expected_amount_minor');
            $table->char('currency_code', 3);
            $table->string('status', 20)->default('due');
            $table->foreignId('contribution_record_id')->nullable()->constrained('contribution_records')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['contribution_cycle_id', 'user_id'], 'cco_cycle_user_unique');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_cycle_obligations');
    }
};
