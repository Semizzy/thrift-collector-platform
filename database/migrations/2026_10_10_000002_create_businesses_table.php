<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 180)->unique();
            $table->char('country_code', 2);
            $table->string('status', 24)->default('pending_review');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->foreign('country_code')->references('code')->on('countries')->restrictOnDelete();
            $table->index(['country_code', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
