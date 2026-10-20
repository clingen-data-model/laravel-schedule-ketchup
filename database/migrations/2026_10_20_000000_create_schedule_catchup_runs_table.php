<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_catchup_runs', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->timestamp('tracked_since');
            $table->timestamp('last_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_catchup_runs');
    }
};
