<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('thrift_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->char('currency_code', 3);
            $table->string('status', 24)->default('draft');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'status']);
        });

        Schema::create('thrift_group_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('thrift_group_id')->constrained('thrift_groups')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 32)->default('member');
            $table->string('status', 24)->default('active');
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['thrift_group_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thrift_group_memberships');
        Schema::dropIfExists('thrift_groups');
    }
};
