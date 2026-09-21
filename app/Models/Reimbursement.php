<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'member_id', 'benefit_period_id', 'or_no', 'or_date', 'or_amount', 'hospital_name', 'description', 'remarks',
    'is_voided', 'voided_at', 'voided_reason', 'voided_by',
])]
class Reimbursement extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected function casts(): array
    {
        return [
            'or_date' => 'date',
            'or_amount' => 'decimal:2',
            'is_voided' => 'boolean',
            'voided_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * The Coverage Year / Benefit Period this reimbursement's OR date
     * falls into (see ReimbursementController::linkToBenefitPeriod()).
     * Nullable — reimbursements filed before this link existed, or ones
     * backdated into a coverage year with no BenefitPeriod row yet, may
     * not have one.
     */
    public function benefitPeriod(): BelongsTo
    {
        return $this->belongsTo(BenefitPeriod::class);
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->where('is_voided', false);
    }

    public function void(string $reason, ?User $by = null): void
    {
        $this->update([
            'is_voided' => true,
            'voided_at' => now(),
            'voided_reason' => $reason,
            'voided_by' => $by?->id,
        ]);
    }

    public function unvoid(): void
    {
        $this->update([
            'is_voided' => false,
            'voided_at' => null,
            'voided_reason' => null,
            'voided_by' => null,
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('reimbursement')
            ->logOnly([
                'member_id', 'or_no', 'or_date', 'or_amount', 'hospital_name',
                'is_voided', 'voided_reason',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
