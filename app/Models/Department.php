<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['name', 'division_id'])]
class Department extends Model
{
    use HasFactory, LogsActivity;

    /**
     * Dropdown label. Names are NOT unique (task.md §2.36) — records are told
     * apart by id — so App\Support\OptionLabels adds " #id" to a name that
     * repeats. Without that, this is just the name.
     */
    protected function optionLabel(): Attribute
    {
        return Attribute::make(get: fn ($value) => $value ?? $this->name);
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('department')
            ->logOnly(['name', 'division_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
