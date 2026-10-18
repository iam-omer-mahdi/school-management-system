<?php

use App\Actions\SecurityQuestionRecovery;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    public string $password = '';

    public string $password_confirmation = '';

    public string $answer_1 = '';

    public string $answer_2 = '';

    public string $answer_3 = '';

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'password' => __('settings.password'),
            'answer_1' => __('settings.answer', ['n' => 1]),
            'answer_2' => __('settings.answer', ['n' => 2]),
            'answer_3' => __('settings.answer', ['n' => 3]),
        ];
    }

    /**
     * Only a flagged account belongs here, so a missed flag does not quietly
     * turn into a "change your password without knowing it" page.
     */
    public function mount(): void
    {
        if (! auth()->user()->must_change_password) {
            $this->redirectRoute('dashboard');
        }
    }

    public function save(SecurityQuestionRecovery $recovery): void
    {
        $this->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'answer_1' => ['required', 'string', 'min:2'],
            'answer_2' => ['required', 'string', 'min:2'],
            'answer_3' => ['required', 'string', 'min:2'],
        ]);

        $user = auth()->user();

        DB::transaction(function () use ($user, $recovery): void {
            $user->password = $this->password;
            $user->must_change_password = false;
            $user->save();

            $recovery->updateQuestions($user, [
                'answer_1' => $this->answer_1,
                'answer_2' => $this->answer_2,
                'answer_3' => $this->answer_3,
            ]);
        });

        $this->redirectRoute('dashboard', navigate: true);
    }
};
?>

<div class="space-y-4">
    <x-ui.card :title="__('auth.first_login_title')" :description="__('auth.first_login_notice')">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('settings.password')" for="first-login-password" required :error="$errors->first('password')">
                    <x-ui.input id="first-login-password" type="password" wire:model.blur="password" :error="$errors->first('password')" autocomplete="new-password" required />
                </x-ui.field>
                <x-ui.field :label="__('settings.password_confirmation')" for="first-login-password-confirmation" required>
                    <x-ui.input id="first-login-password-confirmation" type="password" wire:model.blur="password_confirmation" autocomplete="new-password" required />
                </x-ui.field>
            </div>

            <x-ui.alert type="info">{{ __('settings.security_invented_hint') }}</x-ui.alert>

            @php($securityQuestions = SecurityQuestionRecovery::questions())
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $securityQuestions[0] }}</div>
                <x-ui.field :label="__('settings.answer', ['n' => 1])" for="first-login-answer-1" required :error="$errors->first('answer_1')">
                    <x-ui.input id="first-login-answer-1" type="password" wire:model.blur="answer_1" :error="$errors->first('answer_1')" autocomplete="off" required />
                </x-ui.field>
                <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $securityQuestions[1] }}</div>
                <x-ui.field :label="__('settings.answer', ['n' => 2])" for="first-login-answer-2" required :error="$errors->first('answer_2')">
                    <x-ui.input id="first-login-answer-2" type="password" wire:model.blur="answer_2" :error="$errors->first('answer_2')" autocomplete="off" required />
                </x-ui.field>
                <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $securityQuestions[2] }}</div>
                <x-ui.field :label="__('settings.answer', ['n' => 3])" for="first-login-answer-3" required :error="$errors->first('answer_3')">
                    <x-ui.input id="first-login-answer-3" type="password" wire:model.blur="answer_3" :error="$errors->first('answer_3')" autocomplete="off" required />
                </x-ui.field>
            </div>

            <x-ui.button type="submit" variant="primary" loading-target="save" wire:loading.attr="disabled">{{ __('auth.first_login_submit') }}</x-ui.button>
        </form>
    </x-ui.card>

    <form method="POST" action="{{ route('logout') }}" class="text-center">
        @csrf
        <x-ui.button type="submit" variant="ghost" size="sm" :spinner="false">{{ __('common.logout') }}</x-ui.button>
    </form>
</div>
