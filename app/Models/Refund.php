<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use Carbon\CarbonInterface;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A create-only refund of money already paid against one enrollment
 * fee. Only paid fees of ended enrollments can be refunded, and the
 * sum of a fee's non-voided refunds can never exceed its net paid
 * (paid minus refunded). Corrections are voids (voided_at +
 * void_reason), never edits or deletes.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property int $enrollment_fee_id
 * @property int $amount_minor
 * @property PaymentMethod $method
 * @property string|null $transaction_number
 * @property CarbonInterface|null $refunded_on
 * @property string $note
 * @property CarbonInterface|null $voided_at
 * @property string|null $void_reason
 */
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    protected $fillable = [
        'enrollment_id',
        'enrollment_fee_id',
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

    /** @return BelongsTo<EnrollmentFee, $this> */
    public function enrollmentFee(): BelongsTo
    {
        return $this->belongsTo(EnrollmentFee::class);
    }
}
