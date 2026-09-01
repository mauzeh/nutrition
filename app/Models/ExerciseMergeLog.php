<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class ExerciseMergeLog extends Model
{
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['source_exercise_id', 'source_exercise_title', 'target_exercise_id', 'target_exercise_title', 'admin_user_id', 'admin_email', 'lift_log_ids', 'lift_log_count', 'alias_created'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }


    protected $fillable = [
        'source_exercise_id',
        'source_exercise_title',
        'target_exercise_id',
        'target_exercise_title',
        'admin_user_id',
        'admin_email',
        'lift_log_ids',
        'lift_log_count',
        'alias_created',
        'snapshot',
    ];

    protected $casts = [
        'lift_log_ids' => 'array',
        'lift_log_count' => 'integer',
        'alias_created' => 'boolean',
        'snapshot' => 'array',
    ];

    /**
     * Get the admin user who performed the merge.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /**
     * Get the target exercise (if it still exists).
     */
    public function targetExercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class, 'target_exercise_id');
    }
}
