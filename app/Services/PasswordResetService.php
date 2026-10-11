<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Single-use reset tokens shared by the OTP flow and the security-question
 * flow. Only hashes are stored; the plain token lives in the HTTP session.
 */
final class PasswordResetService
{
    public const TOKEN_TTL_MINUTES = 15;

    /**
     * Issue a new single-use reset token for the email. Older tokens are
     * invalidated so only one token is active at a time.
     */
    public function issueToken(string $email): string
    {
        $plain = Str::random(64);

        DB::transaction(function () use ($email, $plain): void {
            PasswordResetToken::query()->where('email', $email)->whereNull('used_at')->update([
                'used_at' => now(),
            ]);

            PasswordResetToken::query()->create([
                'email' => $email,
                'token_hash' => Hash::make($plain),
                'expires_at' => now()->addMinutes(self::TOKEN_TTL_MINUTES),
            ]);
        });

        return $plain;
    }

    /**
     * Return the active token for the email when the plain token matches,
     * otherwise null. Does not consume the token.
     */
    public function findValidToken(string $email, string $plain): ?PasswordResetToken
    {
        if ($plain === '') {
            return null;
        }

        $token = PasswordResetToken::query()
            ->where('email', $email)
            ->whereNull('used_at')
            ->latest('id')
            ->first();

        if ($token === null || ! $token->isValid() || ! Hash::check($plain, $token->token_hash)) {
            return null;
        }

        return $token;
    }

    /**
     * Complete the password reset: validate the single-use token, update the
     * password, rotate the remember token (invalidating other devices), and
     * burn every OTP and token for the email.
     *
     * @throws ValidationException
     */
    public function completeReset(string $email, string $plainToken, string $newPassword): void
    {
        $token = $this->findValidToken($email, $plainToken);

        if ($token === null) {
            throw ValidationException::withMessages([
                'password' => __('auth.otp_invalid'),
            ]);
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'password' => __('auth.otp_invalid'),
            ]);
        }

        DB::transaction(function () use ($email, $token, $user, $newPassword): void {
            $user->password = $newPassword;
            // The reset happens while logged out, so Auth::logoutOtherDevices()
            // cannot apply. Rotating the remember token invalidates
            // "remember me" sessions on other devices instead.
            $user->setRememberToken(Str::random(60));
            $user->save();

            $token->used_at = now();
            $token->save();

            PasswordResetToken::query()->where('email', $email)->whereNull('used_at')->update([
                'used_at' => now(),
            ]);

            DB::table('password_reset_otps')->where('email', $email)->delete();
        });
    }
}
