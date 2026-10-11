<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One-time codes proving ownership of a new email address before it
 * replaces the user's current email. Only hashes are stored.
 *
 * @property int $id
 * @property int $user_id
 * @property string $email
 * @property string $code_hash
 * @property CarbonImmutable $expires_at
 * @property int $attempts
 * @property CarbonImmutable|null $used_at
 */
class EmailChangeOtp extends Model
{
    protected $fillable = ['user_id', 'email', 'code_hash', 'expires_at', 'attempts', 'used_at'];

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
}
