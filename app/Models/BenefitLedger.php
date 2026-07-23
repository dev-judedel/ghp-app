<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['member_id', 'from_date', 'to_date', 'ghp_amount', 'available_amount', 'used_amount'])]
class BenefitLedger extends Model
{
    use HasFactory;

    protected $table = 'benefit_ledger';

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'ghp_amount' => 'decimal:2',
            'available_amount' => 'decimal:2',
            'used_amount' => 'decimal:2',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
