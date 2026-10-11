<?php

use App\Actions\SecurityQuestionRecovery;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    public string $email = '';

    public string $current_password = '';

    public string $new_password = '';

    public string $new_password_confirmation = '';

    public string $answer_1 = '';

    public string $answer_2 = '';

    public string $answer_3 = '';

    public bool $showPasswordForm = false;

    public bool $showSecurityForm = false;

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'email' => __('settings.email'),
            'current_password' => __('settings.current_password'),
            'new_password' => __('settings.password'),
            'answer_1' => __('settings.answer', ['n' => 1]),
            'answer_2' => __('settings.answer', ['n' => 2]),
            'answer_3' => __('settings.answer', ['n' => 3]),
        ];
    }

    public function mount(): void
    {
        $user = auth()->user();
        $this->email = (string) $user->email;
    }

    public function updateProfile(): void
    {
        $user = auth()->user();

        $this->validate([
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'new_password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'current_password' => ['required_with:new_password', 'string'],
        ]);

        if ($this->new_password !== '') {
            if (! Hash::check($this->current_password, (string) $user->password)) {
                $this->addError('current_password', __('auth.failed'));

                return;
            }

            $user->password = $this->new_password;
        }

        $user->email = $this->email;
        $user->save();

        $this->reset(['current_password', 'new_password', 'new_password_confirmation']);
        $this->showPasswordForm = false;

        session()->flash('profile', __('settings.profile_updated'));
    }

    public function updateSecurity(SecurityQuestionRecovery $recovery): void
    {
        $this->validate([
            'answer_1' => ['required', 'string', 'min:2'],
            'answer_2' => ['required', 'string', 'min:2'],
            'answer_3' => ['required', 'string', 'min:2'],
        ]);

        $recovery->updateQuestions(auth()->user(), [
            'answer_1' => $this->answer_1,
            'answer_2' => $this->answer_2,
            'answer_3' => $this->answer_3,
        ]);

        $this->reset(['answer_1', 'answer_2', 'answer_3']);
        $this->showSecurityForm = false;

        session()->flash('security', __('settings.security_updated'));
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header :title="__('settings.title')" />

    <x-ui.card :title="__('settings.profile')">
        @if(session('profile'))
            <div class="mb-4"><x-ui.alert type="success" dismissible>{{ session('profile') }}</x-ui.alert></div>
        @endif
        <form wire:submit="updateProfile" class="space-y-4">
            <x-ui.field :label="__('settings.email')" for="email" required :error="$errors->first('email')">
                <x-ui.input id="email" type="email" wire:model.blur="email" :error="$errors->first('email')" autocomplete="email" required />
            </x-ui.field>
            <div>
                <x-ui.button type="button" variant="secondary" size="sm" wire:click="$toggle('showPasswordForm')" :spinner="false">
                    {{ $showPasswordForm ? __('settings.hide_password_form') : __('settings.change_password') }}
                </x-ui.button>
            </div>
            @if($showPasswordForm)
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('settings.current_password')" for="current_password" :error="$errors->first('current_password')">
                        <x-ui.input id="current_password" type="password" wire:model.blur="current_password" :error="$errors->first('current_password')" autocomplete="current-password" />
                    </x-ui.field>
                    <x-ui.field :label="__('settings.password')" for="new_password" :error="$errors->first('new_password')">
                        <x-ui.input id="new_password" type="password" wire:model.blur="new_password" :error="$errors->first('new_password')" autocomplete="new-password" />
                    </x-ui.field>
                </div>
                <x-ui.field :label="__('settings.password_confirmation')" for="new_password_confirmation">
                    <x-ui.input id="new_password_confirmation" type="password" wire:model.blur="new_password_confirmation" autocomplete="new-password" />
                </x-ui.field>
            @endif
            <x-ui.button type="submit" variant="primary" loading-target="updateProfile" wire:loading.attr="disabled">{{ __('common.save') }}</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card :title="__('settings.security')" :description="__('settings.security_hint')">
        @if(session('security'))
            <div class="mb-4"><x-ui.alert type="success" dismissible>{{ session('security') }}</x-ui.alert></div>
        @endif
        <x-ui.button type="button" variant="secondary" size="sm" wire:click="$toggle('showSecurityForm')" :spinner="false">
            {{ $showSecurityForm ? __('settings.hide_security_form') : __('settings.manage_security') }}
        </x-ui.button>
        @if($showSecurityForm)
            @php($securityQuestions = SecurityQuestionRecovery::questions())
            <form wire:submit="updateSecurity" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $securityQuestions[0] }}</div>
                    <x-ui.field :label="__('settings.answer', ['n' => 1])" for="answer_1" required :error="$errors->first('answer_1')">
                        <x-ui.input id="answer_1" type="password" wire:model.blur="answer_1" :error="$errors->first('answer_1')" autocomplete="off" required />
                    </x-ui.field>
                    <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $securityQuestions[1] }}</div>
                    <x-ui.field :label="__('settings.answer', ['n' => 2])" for="answer_2" required :error="$errors->first('answer_2')">
                        <x-ui.input id="answer_2" type="password" wire:model.blur="answer_2" :error="$errors->first('answer_2')" autocomplete="off" required />
                    </x-ui.field>
                    <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $securityQuestions[2] }}</div>
                    <x-ui.field :label="__('settings.answer', ['n' => 3])" for="answer_3" required :error="$errors->first('answer_3')">
                        <x-ui.input id="answer_3" type="password" wire:model.blur="answer_3" :error="$errors->first('answer_3')" autocomplete="off" required />
                    </x-ui.field>
                </div>
                <x-ui.button type="submit" variant="primary" loading-target="updateSecurity" wire:loading.attr="disabled">{{ __('common.save') }}</x-ui.button>
            </form>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('settings.language_title')" :description="__('settings.language_hint')">
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div>
                <p class="mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('settings.language_title') }}</p>
                <form method="POST" action="{{ route('locale.switch') }}" class="flex flex-wrap gap-2">
                    @csrf
                    <x-ui.button type="submit" name="locale" value="en" variant="secondary" size="sm" :spinner="false">{{ __('common.english') }}</x-ui.button>
                    <x-ui.button type="submit" name="locale" value="ar" variant="secondary" size="sm" :spinner="false">{{ __('common.arabic') }}</x-ui.button>
                </form>
            </div>
            <div>
                <p class="mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('settings.appearance_title') }}</p>
                <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">{{ __('settings.appearance_hint') }}</p>
                <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('settings.appearance_title') }}">
                    <x-ui.button type="button" variant="secondary" size="sm" data-theme-set="light" :spinner="false">{{ __('settings.theme_light') }}</x-ui.button>
                    <x-ui.button type="button" variant="secondary" size="sm" data-theme-set="dark" :spinner="false">{{ __('settings.theme_dark') }}</x-ui.button>
                </div>
            </div>
        </div>
    </x-ui.card>
</div>
