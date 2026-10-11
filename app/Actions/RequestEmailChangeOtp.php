<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\EmailChangeOtp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class RequestEmailChangeOtp
{
    public const OTP_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    /**
     * Issue a verification code for the user's new (not yet used) email
     * address. Returns the plain code for mailing. Only one active code per
     * user exists at a time; older ones are invalidated.
     */
    public function request(User $user, string $newEmail): string
    {
        $address = strtolower(trim($newEmail));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($user, $address, $code): void {
            EmailChangeOtp::query()->where('user_id', $user->id)->whereNull('used_at')->update([
                'used_at' => now(),
            ]);

            EmailChangeOtp::query()->create([
                'user_id' => $user->id,
                'email' => $address,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
                'attempts' => 0,
            ]);
        });

        return $code;
    }

    /**
     * Verify the code against the latest active OTP for this user and address.
     * On success the OTP is consumed and the user's email is updated.
     *
     * @throws ValidationException
     */
    public function verify(User $user, string $newEmail, string $code): EmailChangeOtp
    {
        $address = strtolower(trim($newEmail));

        $otp = EmailChangeOtp::query()
            ->where('user_id', $user->id)
            ->where('email', $address)
            ->whereNull('used_at')
            ->latest('id')
            ->first();

        if ($otp === null) {
            throw ValidationException::withMessages([
                'email_code' => __('settings.email_code_invalidated'),
            ]);
        }

        if ($otp->isExpired()) {
            $otp->used_at = now();
            $otp->save();

            throw ValidationException::withMessages([
                'email_code' => __('settings.email_code_invalid'),
            ]);
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            $otp->used_at = now();
            $otp->save();

            throw ValidationException::withMessages([
                'email_code' => __('settings.email_code_invalidated'),
            ]);
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->attempts++;
            if ($otp->attempts >= self::MAX_ATTEMPTS) {
                $otp->used_at = now();
            }
            $otp->save();

            throw ValidationException::withMessages([
                'email_code' => $otp->isUsed() ? __('settings.email_code_invalidated') : __('settings.email_code_invalid'),
            ]);
        }

        if (User::query()->where('email', $address)->where('id', '!=', $user->id)->exists()) {
            $otp->used_at = now();
            $otp->save();

            throw ValidationException::withMessages([
                'new_email' => __('validation.unique', ['attribute' => __('settings.new_email')]),
            ]);
        }

        DB::transaction(function () use ($user, $address, $otp): void {
            $user->email = $address;
            $user->save();

            $otp->used_at = now();
            $otp->save();
        });

        return $otp->refresh();
    }
}
