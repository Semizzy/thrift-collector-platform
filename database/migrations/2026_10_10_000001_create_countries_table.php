<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->char('code', 2)->primary();
            $table->string('name', 80);
            $table->char('currency_code', 3);
            $table->unsignedTinyInteger('minor_unit_digits')->default(2);
            $table->string('locale', 12);
            $table->string('timezone', 64);
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
