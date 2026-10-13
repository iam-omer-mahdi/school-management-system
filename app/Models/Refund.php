<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\RefundKind;
use Carbon\CarbonInterface;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A create-only refund of an overpayment. Corrections are voids
 * (voided_at + void_reason), never edits or deletes.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property RefundKind $kind
 * @property int $amount_minor
 * @property PaymentMethod $method
 * @property string|null $transaction_number
 * @property CarbonInterface|null $refunded_on
 * @property string|null $note
 * @property CarbonInterface|null $voided_at
 * @property string|null $void_reason
 */
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    protected $fillable = [
        'enrollment_id',
        'kind',
        'amount_minor',
        'method',
        'transaction_number',
        'refunded_on',
        'note',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'kind' => RefundKind::class,
            'method' => PaymentMethod::class,
            'refunded_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
