<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['member_id', 'name', 'relation', 'birthdate'])]
class Dependent extends Model
{
    use HasFactory, LogsActivity;

    /**
     * Relation values that trigger the age-21 eligibility cutoff in the legacy
     * system. NOTE: the live data also contains 'Daugther'/'Dauther' (typos)
     * and 'Anak' (Tagalog for child), which the legacy code's exact-match
     * check silently treated as always-eligible regardless of age. Flagged
     * in the migration analysis doc §2.3 for a business-owner decision before
     * this list is finalized — don't assume the exact-match behavior is
     * intentional just because it's what the old code did.
     */
    private const CHILD_RELATIONS = ['Son', 'Daughter', 'Child'];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    protected function age(): Attribute
    {
        return Attribute::get(fn () => $this->birthdate?->age);
    }

    /**
     * Mirrors the legacy eligibility rule: non-child dependents are always
     * eligible; child-relation dependents are eligible only under age 21.
     */
    protected function isEligible(): Attribute
    {
        return Attribute::get(function () {
            if (! in_array($this->relation, self::CHILD_RELATIONS, true)) {
                return true;
            }

            return $this->age !== null && $this->age < 21;
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('dependent')
            ->logOnly(['member_id', 'name', 'relation', 'birthdate'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
