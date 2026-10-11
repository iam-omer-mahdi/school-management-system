<?php

use App\Actions\SecurityQuestionRecovery;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    public string $email = '';

    public string $answer_1 = '';

    public string $answer_2 = '';

    public string $answer_3 = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $done = false;

    public bool $lookedUp = false;

    public function lookup(): void
    {
        $this->validate(['email' => ['required', 'email']]);
        $this->lookedUp = true;
    }

    #[Computed]
    public function user(): ?User
    {
        if ($this->email === '') {
            return null;
        }

        return User::query()->where('email', $this->email)->first(['id', 'email', 'security_question_1', 'security_question_2', 'security_question_3', 'security_answer_1']);
    }

    public function resetPassword(SecurityQuestionRecovery $recovery): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'answer_1' => ['required', 'string'],
            'answer_2' => ['required', 'string'],
            'answer_3' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $recovery->resetWithAnswers($this->email, [
            'answer_1' => $this->answer_1,
            'answer_2' => $this->answer_2,
            'answer_3' => $this->answer_3,
        ], $this->password);

        $this->done = true;
    }
};
?>

<div class="rounded bg-white p-6 shadow">
    <h1 class="mb-4 text-xl font-semibold">{{ __('auth.recovery_title') }}</h1>

    @if($done)
        <p class="rounded bg-green-100 p-3 text-sm text-green-800">{{ __('auth.reset_done') }}</p>
        <a href="{{ route('login') }}" wire:navigate class="mt-3 inline-block text-blue-700 hover:underline">{{ __('auth.title') }}</a>
    @else
        <form wire:submit="lookup" class="mb-4 space-y-3">
            <x-field :label="__('auth.email')" :error="$errors->first('email')">
                <input type="email" wire:model.blur="email" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
            <button type="submit" class="w-full rounded border px-3 py-2 hover:bg-gray-100">{{ __('common.search') }}</button>
        </form>

        @if($lookedUp && $this->user && $this->user->security_question_1)
            <div class="mb-3 space-y-1 text-sm text-gray-700">
                <div>1. {{ $this->user->security_question_1 }}</div>
                <div>2. {{ $this->user->security_question_2 }}</div>
                <div>3. {{ $this->user->security_question_3 }}</div>
            </div>
            <form wire:submit="resetPassword" class="space-y-3 border-t pt-4">
                <x-field :label="__('auth.answer').' 1'" :error="$errors->first('answer_1')">
                    <input type="password" wire:model.blur="answer_1" class="w-full rounded border-gray-300 px-3 py-2" />
                </x-field>
                <x-field :label="__('auth.answer').' 2'" :error="$errors->first('answer_2')">
                    <input type="password" wire:model.blur="answer_2" class="w-full rounded border-gray-300 px-3 py-2" />
                </x-field>
                <x-field :label="__('auth.answer').' 3'" :error="$errors->first('answer_3')">
                    <input type="password" wire:model.blur="answer_3" class="w-full rounded border-gray-300 px-3 py-2" />
                </x-field>
                <x-field :label="__('auth.new_password')" :error="$errors->first('password')">
                    <input type="password" wire:model.blur="password" class="w-full rounded border-gray-300 px-3 py-2" autocomplete="new-password" />
                </x-field>
                <x-field :label="__('auth.new_password_confirmation')">
                    <input type="password" wire:model.blur="password_confirmation" class="w-full rounded border-gray-300 px-3 py-2" autocomplete="new-password" />
                </x-field>
                <button type="submit" wire:loading.attr="disabled" class="w-full rounded bg-gray-900 px-3 py-2 text-white hover:bg-gray-700">{{ __('auth.otp_verify') }}</button>
            </form>
        @elseif($lookedUp)
            <p class="text-sm text-red-700">{{ __('auth.recovery_unavailable') }}</p>
        @endif
    @endif
</div>
