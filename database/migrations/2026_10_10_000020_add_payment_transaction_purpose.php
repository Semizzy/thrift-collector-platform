<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('deposit_intents', function (Blueprint $table): void {
            $table->string('purpose', 255)->nullable();
        });
        Schema::table('payout_requests', function (Blueprint $table): void {
            $table->string('purpose', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payout_requests', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
        Schema::table('deposit_intents', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
    }
};
