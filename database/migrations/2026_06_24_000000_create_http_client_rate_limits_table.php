<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('http_client_rate_limits', function (Blueprint $table): void {
            $table->id();
            $table->string('owner')->index();
            // Request timestamp in milliseconds; null on penalty-only rows.
            $table->unsignedBigInteger('hit_at')->nullable()->index();
            // Server-imposed "do not send before" timestamp (ms) for adaptive limiting.
            $table->unsignedBigInteger('penalized_until')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
