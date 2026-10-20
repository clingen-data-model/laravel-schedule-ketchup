<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_runs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('command')->nullable();
            $table->boolean('scheduled');
            $table->string('host')->nullable();
            $table->dateTime('started_at');
            // Usage columns stay null until the run finishes, and for what can't be measured.
            $table->integer('exit_code')->nullable();
            $table->decimal('wall_seconds', 10, 3)->nullable();
            $table->decimal('cpu_user_seconds', 10, 3)->nullable();
            $table->decimal('cpu_system_seconds', 10, 3)->nullable();
            $table->unsignedBigInteger('max_rss_kb')->nullable();
            $table->unsignedBigInteger('php_peak_bytes')->nullable();
            $table->decimal('children_cpu_seconds', 10, 3)->nullable();
            $table->unsignedBigInteger('children_max_rss_kb')->nullable();

            $table->index(['name', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_runs');
    }
};
