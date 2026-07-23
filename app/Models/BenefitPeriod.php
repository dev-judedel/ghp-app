<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'member_id', 'from_date', 'to_date', 'ghp_amount', 'ghp_available',
    'ghp_used', 'member_type', 'remarks', 'division_id', 'department_id',
])]
class BenefitPeriod extends Model
{
    use HasFactory;

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
}
