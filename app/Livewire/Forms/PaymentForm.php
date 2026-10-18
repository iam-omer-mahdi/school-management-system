<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Support\BusinessDate;
use App\Support\PaymentCalculation;
use Livewire\Form;

class PaymentForm extends Form
{
    public string $method = 'cash';

    public ?string $txn = null;

    public ?string $date = null;

    public ?string $note = null;

    public string $total = '';

    /** @var array<int, string> */
    public array $alloc = [];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'method' => ['required', 'in:cash,bank_transfer'],
            'txn' => ['nullable', 'string', 'max:100'],
            'date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            'total' => ['required', 'string'],
            'alloc' => ['array'],
            'alloc.*' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'method' => __('payments.method'),
            'txn' => __('payments.transaction_number'),
            'date' => __('payments.paid_on'),
            'note' => __('fees.note'),
            'total' => __('payments.amount'),
            'alloc' => __('payments.amount'),
            'alloc.*' => __('payments.amount'),
        ];
    }

    public function parsedTotal(): ?int
    {
        return PaymentCalculation::parseTotal($this->total);
    }

    /** @return array<int, int>|null */
    public function parsedAlloc(): ?array
    {
        return PaymentCalculation::parseAllocations($this->alloc);
    }

    public function unallocated(): ?int
    {
        return PaymentCalculation::unallocated($this->parsedTotal(), $this->parsedAlloc());
    }

    /** @return array<int, array{grade_fee_id: int, amount_minor: int}> */
    public function rows(): array
    {
        $allocs = $this->parsedAlloc();

        if ($allocs === null) {
            return [];
        }

        return PaymentCalculation::toRows($allocs);
    }

    public function resetForm(?string $today = null): void
    {
        $this->reset(['method', 'txn', 'date', 'note', 'total', 'alloc']);
        $this->method = 'cash';
        $this->date = $today ?? BusinessDate::today();
    }
}
