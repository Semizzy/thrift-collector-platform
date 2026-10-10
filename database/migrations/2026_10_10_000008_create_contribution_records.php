<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('contribution_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('thrift_group_id')->constrained('thrift_groups')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('status', 24)->default('recorded');
            $table->string('collection_method', 24)->default('unspecified');
            $table->string('reference', 120)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['thrift_group_id', 'status', 'created_at']);
            $table->unique(['thrift_group_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_records');
    }
};
