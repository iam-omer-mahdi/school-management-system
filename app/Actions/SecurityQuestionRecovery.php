<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class SecurityQuestionRecovery
{
    /**
     * @param  array{answer_1: string, answer_2: string, answer_3: string}  $answers
     *
     * @throws ValidationException
     */
    public function resetWithAnswers(string $email, array $answers, string $newPassword): void
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || $user->security_answer_1 === null) {
            throw ValidationException::withMessages([
                'email' => __('auth.recovery_unavailable'),
            ]);
        }

        $valid = Hash::check($answers['answer_1'], (string) $user->security_answer_1)
            && Hash::check($answers['answer_2'], (string) $user->security_answer_2)
            && Hash::check($answers['answer_3'], (string) $user->security_answer_3);

        if (! $valid) {
            throw ValidationException::withMessages([
                'answer_1' => __('auth.recovery_invalid'),
            ]);
        }

        $user->password = $newPassword;
        $user->save();
    }

    /**
     * @param  array{question_1: string, answer_1: string, question_2: string, answer_2: string, question_3: string, answer_3: string}  $data
     */
    public function updateQuestions(User $user, array $data): User
    {
        $user->security_question_1 = $data['question_1'];
        $user->security_answer_1 = Hash::make($data['answer_1']);
        $user->security_question_2 = $data['question_2'];
        $user->security_answer_2 = Hash::make($data['answer_2']);
        $user->security_question_3 = $data['question_3'];
        $user->security_answer_3 = Hash::make($data['answer_3']);
        $user->save();

        return $user->refresh();
    }
}
