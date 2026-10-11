<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $email
 * @property string $code_hash
 * @property CarbonImmutable $expires_at
 * @property int $attempts
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $used_at
 */
class PasswordResetOtp extends Model
{
    protected $fillable = ['email', 'code_hash', 'expires_at', 'attempts', 'verified_at', 'used_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isActive(): bool
    {
        return ! $this->isUsed() && ! $this->isVerified() && ! $this->isExpired();
    }
}
