<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

final class RequestPasswordOtp
{
    public const TTL_MINUTES = 15;

    public function handle(string $email): void
    {
        $user = User::query()->where('email', $email)->first();

        // Do not reveal whether the email exists.
        if ($user === null) {
            return;
        }

        $code = (string) random_int(100000, 999999);

        PasswordResetOtp::query()->where('email', $user->email)->delete();

        PasswordResetOtp::query()->create([
            'email' => $user->email,
            'token_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        $message = __('auth.otp_email_body', ['code' => $code]);

        Mail::raw($message, function ($mail) use ($user): void {
            $mail->to($user->email)->subject(__('auth.otp_email_subject'));
        });
    }

    /**
     * @throws ValidationException
     */
    public function resetWithOtp(string $email, string $code, string $newPassword): void
    {
        $otp = PasswordResetOtp::query()
            ->where('email', $email)
            ->latest('id')
            ->first();

        if ($otp === null || $otp->isExpired() || ! Hash::check($code, $otp->token_hash)) {
            throw ValidationException::withMessages([
                'code' => __('auth.otp_invalid'),
            ]);
        }

        $user = User::query()->where('email', $email)->firstOrFail();
        $user->password = $newPassword;
        $user->save();

        PasswordResetOtp::query()->where('email', $email)->delete();
    }
}
