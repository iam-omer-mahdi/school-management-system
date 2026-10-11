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

    public string $question_1 = '';

    public string $answer_1 = '';

    public string $question_2 = '';

    public string $answer_2 = '';

    public string $question_3 = '';

    public string $answer_3 = '';

    public function mount(): void
    {
        $user = auth()->user();
        $this->email = (string) $user->email;
        $this->question_1 = (string) ($user->security_question_1 ?? '');
        $this->question_2 = (string) ($user->security_question_2 ?? '');
        $this->question_3 = (string) ($user->security_question_3 ?? '');
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

        session()->flash('profile', __('settings.profile_updated'));
    }

    public function updateSecurity(SecurityQuestionRecovery $recovery): void
    {
        $this->validate([
            'question_1' => ['required', 'string', 'max:255'],
            'answer_1' => ['required', 'string', 'min:2'],
            'question_2' => ['required', 'string', 'max:255'],
            'answer_2' => ['required', 'string', 'min:2'],
            'question_3' => ['required', 'string', 'max:255'],
            'answer_3' => ['required', 'string', 'min:2'],
        ]);

        $recovery->updateQuestions(auth()->user(), [
            'question_1' => $this->question_1,
            'answer_1' => $this->answer_1,
            'answer_2' => $this->answer_2,
            'question_2' => $this->question_2,
            'question_3' => $this->question_3,
            'answer_3' => $this->answer_3,
        ]);

        $this->reset(['answer_1', 'answer_2', 'answer_3']);

        session()->flash('security', __('settings.security_updated'));
    }
};
?>

<div class="space-y-4">
    <h1 class="text-xl font-semibold">{{ __('settings.title') }}</h1>

    <div class="rounded bg-white p-4 shadow">
        <h2 class="mb-3 font-medium">{{ __('settings.profile') }}</h2>
        @if(session('profile'))
            <p class="mb-2 rounded bg-green-100 p-2 text-sm text-green-800">{{ session('profile') }}</p>
        @endif
        <form wire:submit="updateProfile" class="space-y-3">
            <x-field :label="__('settings.email')" :error="$errors->first('email')">
                <input type="email" wire:model.blur="email" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
            <x-field :label="__('settings.current_password')" :error="$errors->first('current_password')">
                <input type="password" wire:model.blur="current_password" class="w-full rounded border-gray-300 px-3 py-2" autocomplete="current-password" />
            </x-field>
            <x-field :label="__('settings.password')" :error="$errors->first('new_password')">
                <input type="password" wire:model.blur="new_password" class="w-full rounded border-gray-300 px-3 py-2" autocomplete="new-password" />
            </x-field>
            <x-field :label="__('settings.password_confirmation')">
                <input type="password" wire:model.blur="new_password_confirmation" class="w-full rounded border-gray-300 px-3 py-2" autocomplete="new-password" />
            </x-field>
            <button type="submit" wire:loading.attr="disabled" class="rounded bg-gray-900 px-4 py-2 text-white hover:bg-gray-700">{{ __('common.save') }}</button>
        </form>
    </div>

    <div class="rounded bg-white p-4 shadow">
        <h2 class="mb-1 font-medium">{{ __('settings.security') }}</h2>
        <p class="mb-3 text-sm text-gray-600">{{ __('settings.security_hint') }}</p>
        @if(session('security'))
            <p class="mb-2 rounded bg-green-100 p-2 text-sm text-green-800">{{ session('security') }}</p>
        @endif
        <form wire:submit="updateSecurity" class="space-y-3">
            <x-field :label="__('settings.question', ['n' => 1])" :error="$errors->first('question_1')">
                <input type="text" wire:model.blur="question_1" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
            <x-field :label="__('settings.answer', ['n' => 1])" :error="$errors->first('answer_1')">
                <input type="password" wire:model.blur="answer_1" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
            <x-field :label="__('settings.question', ['n' => 2])" :error="$errors->first('question_2')">
                <input type="text" wire:model.blur="question_2" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
            <x-field :label="__('settings.answer', ['n' => 2])" :error="$errors->first('answer_2')">
                <input type="password" wire:model.blur="answer_2" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
            <x-field :label="__('settings.question', ['n' => 3])" :error="$errors->first('question_3')">
                <input type="text" wire:model.blur="question_3" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
            <x-field :label="__('settings.answer', ['n' => 3])" :error="$errors->first('answer_3')">
                <input type="password" wire:model.blur="answer_3" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
            <button type="submit" wire:loading.attr="disabled" class="rounded bg-gray-900 px-4 py-2 text-white hover:bg-gray-700">{{ __('common.save') }}</button>
        </form>
    </div>

    <div class="rounded bg-white p-4 shadow">
        <h2 class="mb-1 font-medium">{{ __('settings.language_title') }}</h2>
        <p class="mb-3 text-sm text-gray-600">{{ __('settings.language_hint') }}</p>
        <form method="POST" action="{{ route('locale.switch') }}">
            @csrf
            <div class="flex gap-2">
                <button type="submit" name="locale" value="en" class="rounded border px-4 py-2 hover:bg-gray-100">{{ __('common.english') }}</button>
                <button type="submit" name="locale" value="ar" class="rounded border px-4 py-2 hover:bg-gray-100">{{ __('common.arabic') }}</button>
            </div>
        </form>
    </div>
</div>
