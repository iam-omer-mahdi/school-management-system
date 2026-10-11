<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate();

        $key = 'login:'.strtolower(trim($this->email)).'|'.$this->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($key);

        session()->regenerate();

        $user = Auth::user();
        $userLocale = $user !== null ? ($user->getAttributes()['display_locale'] ?? null) : null;
        if (is_string($userLocale)) {
            session()->put('locale', $userLocale);
        }

        $this->redirectRoute('dashboard', navigate: true);
    }

    private function ip(): string
    {
        return (string) request()->ip();
    }
};
?>

<div class="rounded bg-white p-6 shadow">
    <h1 class="mb-4 text-xl font-semibold">{{ __('auth.title') }}</h1>
    <form wire:submit="login" class="space-y-3">
        <x-field :label="__('auth.email')" :error="$errors->first('email')">
            <input type="email" wire:model.blur="email" class="w-full rounded border-gray-300 px-3 py-2" autocomplete="email" />
        </x-field>
        <x-field :label="__('auth.password')" :error="$errors->first('password')">
            <input type="password" wire:model.blur="password" class="w-full rounded border-gray-300 px-3 py-2" autocomplete="current-password" />
        </x-field>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model="remember" />
            {{ __('auth.remember') }}
        </label>
        <button type="submit" wire:loading.attr="disabled" class="w-full rounded bg-gray-900 px-3 py-2 text-white hover:bg-gray-700">
            <span wire:loading.remove>{{ __('auth.submit') }}</span>
            <span wire:loading>{{ __('common.loading') }}</span>
        </button>
    </form>
    <div class="mt-4 flex flex-col gap-1 text-sm">
        <a href="{{ route('password.request') }}" wire:navigate class="text-blue-700 hover:underline">{{ __('auth.forgot') }}</a>
        <a href="{{ route('recovery.questions') }}" wire:navigate class="text-blue-700 hover:underline">{{ __('auth.recover_questions') }}</a>
    </div>
</div>
