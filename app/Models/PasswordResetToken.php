<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Single-use password reset proof issued after OTP or security-question
 * verification. Only the hash is stored; the plain token lives in the session.
 *
 * @property int $id
 * @property string $email
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 */
class PasswordResetToken extends Model
{
    protected $table = 'password_reset_grants';

    protected $fillable = ['email', 'token_hash', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
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

    public function isValid(): bool
    {
        return ! $this->isUsed() && ! $this->isExpired();
    }
}
