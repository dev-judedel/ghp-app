<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'member_id', 'from_date', 'to_date', 'ghp_amount', 'ghp_available',
    'ghp_used', 'member_type', 'remarks', 'division_id', 'department_id',
])]
class BenefitPeriod extends Model
{
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'ghp_amount' => 'decimal:2',
            'ghp_available' => 'decimal:2',
            'ghp_used' => 'decimal:2',
            'member_type' => 'integer',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * logOnlyDirty() + dontSubmitEmptyLogs() means this only creates a log
     * entry when a value genuinely changes — a no-op recompute (e.g. the
     * daily auto-generation job re-touching a period where nothing actually
     * moved) produces zero entries. Real changes DO get logged, whether
     * from routine accrual (a new reimbursement changed ghp_used) or a
     * manual correction from the Data Quality Report — both are worth an
     * audit trail, just without the noise of "nothing happened" entries.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('benefit_period')
            ->logOnly(['from_date', 'to_date', 'ghp_amount', 'ghp_used', 'ghp_available'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
