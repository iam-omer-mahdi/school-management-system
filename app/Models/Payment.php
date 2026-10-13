<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use Carbon\CarbonInterface;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A create-only payment row. Fee-linked rows target exactly one
 * enrollment fee; overpayment rows have no fee. Corrections are
 * voids (voided_at + void_reason), never edits or deletes.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property int|null $enrollment_fee_id
 * @property int $amount_minor
 * @property PaymentKind $kind
 * @property PaymentMethod $method
 * @property string|null $transaction_number
 * @property CarbonInterface|null $paid_on
 * @property string|null $note
 * @property CarbonInterface|null $voided_at
 * @property string|null $void_reason
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'enrollment_id',
        'enrollment_fee_id',
        'amount_minor',
        'kind',
        'method',
        'transaction_number',
        'paid_on',
        'note',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'kind' => PaymentKind::class,
            'method' => PaymentMethod::class,
            'paid_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function isOverpayment(): bool
    {
        return $this->kind === PaymentKind::Overpayment;
    }

    /**
     * Display reference derived from the id. Never stored, so it can
     * never drift from the row it names.
     */
    public function reference(): string
    {
        return sprintf('PAY-%06d', $this->id);
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
