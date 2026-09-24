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
use App\Support\UniqueCodeGenerator;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'code', 'email', 'member_type', 'last_name', 'first_name', 'middle_name', 'address',
    'birthdate', 'civil_status', 'apply_date', 'start_date', 'deduction_start_date', 'ghp_amount',
    'ghp_amount_is_manual', 'remarks', 'division_id', 'department_id', 'old_code', 'is_active',
    'resignation_date',
])]
class Member extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    /**
     * Prefix for the system-generated member code (ALSC-######). The admin
     * never types this in — see StoreMemberRequest, which has no 'code'
     * field at all.
     */
    public const CODE_PREFIX = 'ALSC-';

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
            'start_date' => 'date',
            'deduction_start_date' => 'date',
            'ghp_amount' => 'decimal:2',
            'ghp_amount_is_manual' => 'boolean',
            'is_active' => 'boolean',
            'resignation_date' => 'date',
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
     * Generates a unique ALSC-###### code for a new member. Used by
     * MemberController::store() — never accepted from the request (see
     * StoreMemberRequest, which has no 'code' rule at all).
     */
    public static function generateUniqueCode(): string
    {
        return UniqueCodeGenerator::generate('members', 'code', self::CODE_PREFIX);
    }

    /**
     * Shared filter logic for the Members index page AND the CSV/PDF/Excel
     * exports, so the two can never drift apart in which members they show
     * (see MemberController::index() and MemberExportController).
     */
    public static function filtered(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $type = $filters['type'] ?? null;
        $departmentId = $filters['department'] ?? null;
        $divisionId = $filters['division'] ?? null;
        $status = $filters['status'] ?? 'active';

        return static::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%");
                });
            })
            ->when($type === 'employee', fn ($q) => $q->employees())
            ->when($type === 'agent', fn ($q) => $q->agents())
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->when($divisionId, fn ($q) => $q->where('division_id', $divisionId))
            ->when($status === 'active', fn ($q) => $q->active())
            ->when($status === 'inactive', fn ($q) => $q->inactive());
            // $status === 'all' -> no filter applied, shows both
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('member')
            ->logOnly([
                'code', 'email', 'member_type', 'last_name', 'first_name', 'middle_name', 'address',
                'birthdate', 'civil_status', 'apply_date', 'start_date', 'deduction_start_date', 'ghp_amount',
                'ghp_amount_is_manual', 'division_id', 'department_id', 'old_code', 'is_active',
                'resignation_date',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
