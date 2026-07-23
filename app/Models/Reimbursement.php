<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['member_id', 'or_no', 'or_date', 'or_amount', 'hospital_name', 'description', 'remarks'])]
class Reimbursement extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'or_date' => 'date',
            'or_amount' => 'decimal:2',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
