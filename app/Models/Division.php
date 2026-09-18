<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
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

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }
}
