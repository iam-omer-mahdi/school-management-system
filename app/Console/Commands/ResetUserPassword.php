<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PasswordResetOtp;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

final class ResetUserPassword extends Command
{
    /**
     * The password is deliberately absent from the signature so it never
     * lands in shell history; it is collected with hidden prompts instead.
     *
     * @var string
     */
    protected $signature = 'user:reset-password {email : The email address of the user}';

    /**
     * @var string
     */
    protected $description = 'Reset a user password (administrator fallback, run on the server)';

    public function handle(): int
    {
        $emailArgument = $this->argument('email');

        if (! is_string($emailArgument)) {
            $this->error('The email argument must be a string.');

            return self::FAILURE;
        }

        $email = strtolower(trim($emailArgument));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with email [{$email}].");

            return self::FAILURE;
        }

        $newPassword = password('New password');
        $confirmedPassword = password('Confirm new password');

        // Password::defaults() always resolves to a rule object (the
        // framework falls back to min(8) when no strict callback applies).
        $rules = ['required', 'string', 'confirmed', Password::defaults()];

        $validator = Validator::make(
            ['password' => $newPassword, 'password_confirmation' => $confirmedPassword],
            ['password' => $rules]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $email, $newPassword): void {
            // The model casts `password` to `hashed`, so assign the plain
            // value and let the cast hash it.
            $user->password = $newPassword;
            $user->must_change_password = true;
            $user->setRememberToken(Str::random(60));
            $user->save();

            PasswordResetOtp::query()->where('email', $email)->delete();
            PasswordResetToken::query()->where('email', $email)->delete();

            if (config('session.driver') === 'database') {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        });

        $this->info("Password reset for [{$email}]. Existing logins have been signed out; the member must set their own password at the next sign-in.");

        return self::SUCCESS;
    }
}
