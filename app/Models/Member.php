<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code', 'member_type', 'last_name', 'first_name', 'middle_name', 'address',
    'birthdate', 'civil_status', 'apply_date', 'deduction_start_date', 'ghp_amount',
    'remarks', 'division_id', 'department_id', 'old_code', 'is_active',
])]
class Member extends Model
{
    use HasFactory, SoftDeletes;

    public const MEMBER_TYPE_EMPLOYEE = 0;
    public const MEMBER_TYPE_AGENT = 1;

    public const CIVIL_STATUS_SINGLE = 0;
    public const CIVIL_STATUS_MARRIED = 1;

    protected function casts(): array
    {
        return [
            'member_type' => 'integer',
            'civil_status' => 'integer',
            'birthdate' => 'date',
            'apply_date' => 'date',
            'deduction_start_date' => 'date',
            'ghp_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function dependents(): HasMany
    {
        return $this->hasMany(Dependent::class);
    }

    public function benefitPeriods(): HasMany
    {
        return $this->hasMany(BenefitPeriod::class);
    }

    public function benefitLedgerEntries(): HasMany
    {
        return $this->hasMany(BenefitLedger::class);
    }

    public function reimbursements(): HasMany
    {
        return $this->hasMany(Reimbursement::class);
    }

    public function amountAdjustments(): HasMany
    {
        return $this->hasMany(BenefitAmountAdjustment::class);
    }

    public function scopeEmployees(Builder $query): Builder
    {
        return $query->where('member_type', self::MEMBER_TYPE_EMPLOYEE);
    }

    public function scopeAgents(Builder $query): Builder
    {
        return $query->where('member_type', self::MEMBER_TYPE_AGENT);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * Computed, not stored — the legacy c_age column went stale between
     * recalculations. Always derive from birthdate instead.
     */
    protected function age(): Attribute
    {
        return Attribute::get(fn () => $this->birthdate?->age);
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(
            fn () => trim("{$this->last_name}, {$this->first_name} {$this->middle_name}")
        );
    }
}
