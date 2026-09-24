<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'member_id', 'from_date', 'to_date', 'ghp_amount', 'ghp_available',
    'ghp_used', 'member_type', 'remarks', 'division_id', 'department_id',
    'is_voided', 'voided_at', 'voided_reason', 'voided_by',
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
            'is_voided' => 'boolean',
            'voided_at' => 'datetime',
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

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function scopeOngoing(Builder $query): Builder
    {
        return $query->where('is_voided', false);
    }

    public function scopeVoided(Builder $query): Builder
    {
        return $query->where('is_voided', true);
    }

    /**
     * Marks this period as voided — see BenefitPeriodController::void().
     * Unlike Reimbursement::void(), there is deliberately no unvoid():
     * the only ways back are Delete (while still voided) or generating a
     * fresh active row for the same cycle (see
     * MemberController::generateBenefitPeriod() and
     * BenefitAccrualService::accrue(), both of which ignore voided rows
     * when deciding whether the current cycle already has an active one).
     */
    public function void(string $reason, ?User $by = null): void
    {
        $this->update([
            'is_voided' => true,
            'voided_at' => now(),
            'voided_reason' => $reason,
            'voided_by' => $by?->id,
        ]);
    }

    /**
     * Reimbursements linked to this specific coverage year (see
     * reimbursements.benefit_period_id and
     * ReimbursementController::linkToBenefitPeriod()) — the Coverage
     * Year History drill-down in BenefitPeriodController::reimbursements().
     */
    public function reimbursements(): HasMany
    {
        return $this->hasMany(Reimbursement::class);
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
            ->logOnly(['from_date', 'to_date', 'ghp_amount', 'ghp_used', 'ghp_available', 'is_voided', 'voided_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
