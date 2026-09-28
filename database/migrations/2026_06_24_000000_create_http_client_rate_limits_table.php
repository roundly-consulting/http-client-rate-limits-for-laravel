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
            // The window series a hit counts toward: "{limit key}:{window}".
            $table->string('owner');
            // Request timestamp in milliseconds.
            $table->unsignedBigInteger('hit_at')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner', 'hit_at']);
        });

        // One row per limit key: the row a DatabaseStore attempt locks so its check and its
        // write are one unit, and where an adaptive limit keeps the server's penalty. The
        // unique owner is what makes creating it race-free.
        Schema::create('http_client_rate_limit_owners', function (Blueprint $table): void {
            $table->id();
            $table->string('owner')->unique();
            // Server-imposed "do not send before" timestamp (ms) for adaptive limiting.
            $table->unsignedBigInteger('penalized_until')->nullable();
            // Last attempt (ms): the write that takes the lock, and the retention sweep's clock.
            $table->unsignedBigInteger('touched_at')->default(0)->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
