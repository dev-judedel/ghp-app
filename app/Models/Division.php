<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['member_type', 'name'])]
class Division extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'member_type' => 'integer',
        ];
    }

    /**
     * Dropdown label. Names are NOT unique (task.md §2.36) — records are told
     * apart by id — so App\Support\OptionLabels adds " #id" to a name that
     * repeats (same name AND member type). Without that, this is just the name.
     */
    protected function optionLabel(): Attribute
    {
        return Attribute::make(get: fn ($value) => $value ?? $this->name);
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }
}
