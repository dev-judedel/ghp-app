<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'member_id', 'from_date', 'to_date', 'record_no', 'coverage_year', 'ghp_amount', 'ghp_available',
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
            'record_no' => 'integer',
            'coverage_year' => 'integer',
            'ghp_amount' => 'decimal:2',
            'ghp_available' => 'decimal:2',
            'ghp_used' => 'decimal:2',
            'member_type' => 'integer',
            'claimed_at' => 'datetime',
            'reverted_at' => 'datetime',
        ];
    }

    /**
     * ================================================================
     * A benefit period ROW is a RECORD (task.md §2.35).
     * ================================================================
     * One coverage cycle can have several rows for the same member (record_no
     * 1, 2, 3 ...). A record collects every reimbursement filed until it is
     * CLAIMED; then it is locked, and the next reimbursement filed opens a new
     * record for the same cycle. The rows are separate reimbursement-history
     * GROUPS, never separate grants of the annual benefit: the cycle's
     * entitlement is computed once per cycle by BenefitAccrualService::calculate(),
     * and each record stores only its own share (accrue()).
     *
     * Anything that wants "the cycle's figures" from several records must go
     * through summarizeCycle() instead of summing or picking a row.
     */

    /**
     * The cycle's single set of figures from its records (all records of ONE
     * member and ONE coverage cycle): the entitlement once (same on every
     * record), Used summed across the records (each holds only its own share, so
     * nothing is counted twice) and Available as the LAST record's (the balance
     * left after every record). Returns an in-memory copy of the last record —
     * same id and dates — with those figures set; it is never saved.
     *
     * @param  \Illuminate\Support\Collection<int, BenefitPeriod>  $records
     */
    public static function summarizeCycle($records): ?self
    {
        $ordered = $records->sortBy([['record_no', 'asc'], ['id', 'asc']])->values();
        $last = $ordered->last();

        if ($last === null) {
            return null;
        }

        $summary = clone $last;
        $summary->ghp_used = round((float) $ordered->sum('ghp_used'), 2);
        $summary->ghp_available = $last->ghp_available;
        $summary->ghp_amount = $last->ghp_amount;

        return $summary;
    }

    /** True while another record of the same member and cycle exists after this one. */
    public function hasLaterRecord(): bool
    {
        return static::query()
            ->where('member_id', $this->member_id)
            ->whereDate('from_date', $this->from_date->toDateString())
            ->whereDate('to_date', $this->to_date->toDateString())
            ->where('record_no', '>', $this->record_no)
            ->exists();
    }

    /**
     * Claimed / Unclaimed status shown in Coverage Year History. Claimed is
     * exactly "claimed_at is set" — one source of truth, no separate flag.
     * A claimed record is LOCKED: it receives no further reimbursements, and its
     * stored figures are never recalculated. It is never read by the GHP
     * calculation itself.
     */
    public function isClaimed(): bool
    {
        return $this->claimed_at !== null;
    }

    /**
     * True while the period's most recent claim has been reverted and it has
     * not been claimed again since: Unclaimed AND reverted_at is set. This is
     * what makes a second Revert refusable ("No further revert is available")
     * and lets the history show that the period was reverted.
     */
    public function isReverted(): bool
    {
        return $this->claimed_at === null && $this->reverted_at !== null;
    }

    public function markClaimed(User $by): void
    {
        // forceFill on purpose: these columns are deliberately NOT in the
        // mass-assignable list, so no form payload can ever set them. A new
        // claim starts a fresh claim: any earlier revert marker is cleared
        // (the earlier revert stays in the activity log).
        $this->forceFill([
            'claimed_at' => now(), 'claimed_by' => $by->id,
            'reverted_at' => null, 'reverted_by' => null,
        ])->save();
    }

    /**
     * Reverses a claim: back to Unclaimed, with who/when recorded on the
     * period itself. Replaces the old Cancel (which only blanked the claim
     * columns and recorded nothing on the period). The period row is never
     * deleted and no GHP figure, reimbursement or excess is touched — a claim
     * only ever set this status, so this status is the whole of what there is
     * to reverse.
     */
    public function markReverted(User $by): void
    {
        $this->forceFill([
            'claimed_at' => null, 'claimed_by' => null,
            'reverted_at' => now(), 'reverted_by' => $by->id,
        ])->save();
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    public function revertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reverted_by');
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
     * Excess deductions recorded for this period — tracking only, never
     * part of the GHP usage / available calculation (see ExcessDeduction).
     */
    public function excessDeductions(): HasMany
    {
        return $this->hasMany(ExcessDeduction::class);
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
            ->logOnly(['from_date', 'to_date', 'ghp_amount', 'ghp_used', 'ghp_available', 'claimed_at', 'reverted_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
