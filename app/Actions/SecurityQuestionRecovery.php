<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\PasswordResetService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class SecurityQuestionRecovery
{
    public function __construct(
        private readonly PasswordResetService $tokens,
    ) {}

    /**
     * The predefined security questions. The same three questions are shown
     * to every user; only the answers differ per account (stored hashed).
     * Position matters: answer_1 always belongs to question 1, and so on.
     *
     * @return list<string>
     */
    public static function questions(): array
    {
        return [
            __('auth.security_q1'),
            __('auth.security_q2'),
            __('auth.security_q3'),
        ];
    }

    /**
     * Verify the three answers (normalized: trimmed and lowercased) against
     * the stored hashes — answer_1 against question 1, and so on — and issue
     * the same single-use reset token the OTP flow uses. The caller stores the
     * plain token in the session; the reset step validates it server-side.
     *
     * @param  array{answer_1: string, answer_2: string, answer_3: string}  $answers
     *
     * @throws ValidationException
     */
    public function verifyAndIssueToken(string $email, array $answers): string
    {
        $user = User::query()->where('email', strtolower(trim($email)))->first();

        if ($user === null || $user->security_answer_1 === null) {
            throw ValidationException::withMessages([
                'answer_1' => __('auth.recovery_invalid'),
            ]);
        }

        $valid = Hash::check($this->normalize($answers['answer_1']), (string) $user->security_answer_1)
            && Hash::check($this->normalize($answers['answer_2']), (string) $user->security_answer_2)
            && Hash::check($this->normalize($answers['answer_3']), (string) $user->security_answer_3);

        if (! $valid) {
            throw ValidationException::withMessages([
                'answer_1' => __('auth.recovery_invalid'),
            ]);
        }

        return $this->tokens->issueToken((string) $user->email);
    }

    /**
     * @param  array{answer_1: string, answer_2: string, answer_3: string}  $data
     */
    public function updateQuestions(User $user, array $data): User
    {
        $user->security_answer_1 = Hash::make($this->normalize($data['answer_1']));
        $user->security_answer_2 = Hash::make($this->normalize($data['answer_2']));
        $user->security_answer_3 = Hash::make($this->normalize($data['answer_3']));
        $user->save();

        return $user->refresh();
    }

    public function normalize(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }
}
