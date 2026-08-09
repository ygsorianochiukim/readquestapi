<?php

namespace App\Domain\SystemLog\Models;

use App\Domain\Student\Models\Student;
use App\Domain\Teachers\Models\Teachers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail of significant system activity (Table 10 of the data dictionary).
 */
class SystemLog extends Model
{
    protected $table = 'system_logs';

    protected $fillable = [
        'student_id',
        'teacher_id',
        'action',
        'description',
        'ip_address',
    ];

    protected $appends = ['actor'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teachers::class, 'teacher_id');
    }

    /** Human-readable name of whoever triggered the entry. */
    public function getActorAttribute(): string
    {
        if ($this->relationLoaded('teacher') && $this->teacher) {
            return $this->teacher->full_name.' (teacher)';
        }

        if ($this->relationLoaded('student') && $this->student) {
            return $this->student->full_name.' (student)';
        }

        return 'System';
    }
}
