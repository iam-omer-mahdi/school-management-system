<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\PasswordResetOtp;
use App\Models\User;
use App\Services\PasswordResetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class RequestPasswordOtp
{
    public const OTP_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly PasswordResetService $tokens,
    ) {}

    /**
     * Start (or restart) an OTP flow. Returns the plain code for mailing when
     * the email belongs to a user, or null otherwise. Callers must show the
     * same neutral response either way so emails cannot be enumerated.
     */
    public function request(string $email): ?string
    {
        $address = $this->normalizeEmail($email);

        $user = User::query()->where('email', $address)->first();

        if ($user === null) {
            return null;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($address, $code): void {
            // Only one active OTP per email: invalidate older ones.
            PasswordResetOtp::query()->where('email', $address)->whereNull('used_at')->update([
                'used_at' => now(),
            ]);

            PasswordResetOtp::query()->create([
                'email' => $address,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
                'attempts' => 0,
            ]);
        });

        return $code;
    }

    /**
     * Verify an OTP. On success the OTP is marked verified and a single-use
     * reset token is issued.
     *
     * @throws ValidationException
     */
    public function verify(string $email, string $code): PasswordResetOtp
    {
        $address = $this->normalizeEmail($email);

        $otp = PasswordResetOtp::query()
            ->where('email', $address)
            ->whereNull('used_at')
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if ($otp === null) {
            throw ValidationException::withMessages([
                'code' => __('auth.otp_invalidated'),
            ]);
        }

        if ($otp->isExpired()) {
            $otp->used_at = now();
            $otp->save();

            throw ValidationException::withMessages([
                'code' => __('auth.otp_invalid'),
            ]);
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            $otp->used_at = now();
            $otp->save();

            throw ValidationException::withMessages([
                'code' => __('auth.otp_invalidated'),
            ]);
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->attempts++;
            if ($otp->attempts >= self::MAX_ATTEMPTS) {
                $otp->used_at = now();
            }
            $otp->save();

            throw ValidationException::withMessages([
                'code' => $otp->isUsed() ? __('auth.otp_invalidated') : __('auth.otp_invalid'),
            ]);
        }

        $otp->verified_at = now();
        $otp->save();

        return $otp->refresh();
    }

    /**
     * Issue the single-use reset token after a verified OTP.
     *
     * @throws ValidationException
     */
    public function issueResetToken(string $email): string
    {
        $address = $this->normalizeEmail($email);

        $verified = PasswordResetOtp::query()
            ->where('email', $address)
            ->whereNull('used_at')
            ->whereNotNull('verified_at')
            ->latest('id')
            ->first();

        if ($verified === null) {
            throw ValidationException::withMessages([
                'code' => __('auth.otp_invalid'),
            ]);
        }

        return $this->tokens->issueToken($address);
    }

    public function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
