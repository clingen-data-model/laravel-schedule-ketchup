<?php

namespace ScheduleKetchup;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

class ScheduleRun extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scheduled' => 'boolean',
            'started_at' => 'datetime',
            'exit_code' => 'integer',
            'wall_seconds' => 'float',
            'cpu_user_seconds' => 'float',
            'cpu_system_seconds' => 'float',
            'max_rss_kb' => 'integer',
            'php_peak_bytes' => 'integer',
            'children_cpu_seconds' => 'float',
            'children_max_rss_kb' => 'integer',
        ];
    }

    public function prunable(): Builder
    {
        return static::where('started_at', '<', Date::now()->subDays(config('schedule-ketchup.prune_after_days')));
    }
}
