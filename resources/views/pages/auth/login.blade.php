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

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'email' => __('auth.email'),
            'password' => __('auth.password_label'),
        ];
    }

    public function login(): void
    {
        $this->validate();

        $key = 'login:'.strtolower(trim($this->email)).'|'.$this->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password, 'is_active' => true], $this->remember)) {
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

<x-ui.card>
    <x-slot:header>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">{{ __('auth.title') }}</h1>
    </x-slot:header>
    @if(session('status'))
        <div class="mb-4"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif
    <form wire:submit="login" class="space-y-4">
        <x-ui.field :label="__('auth.email')" for="email" required :error="$errors->first('email')">
            <x-ui.input id="email" type="email" wire:model.blur="email" :error="$errors->first('email')" autocomplete="email" required />
        </x-ui.field>
        <x-ui.field :label="__('auth.password_label')" for="password" required :error="$errors->first('password')">
            <x-ui.input id="password" type="password" wire:model.blur="password" :error="$errors->first('password')" autocomplete="current-password" required />
        </x-ui.field>
        <x-ui.checkbox wire:model="remember" :label="__('auth.remember')" />
        <x-ui.button type="submit" variant="primary" size="md" class="w-full" loading-target="login" wire:loading.attr="disabled">{{ __('auth.submit') }}</x-ui.button>
    </form>
    <div class="mt-4 flex flex-col gap-2 text-sm">
        <a href="{{ route('password.request') }}" wire:navigate class="font-medium text-indigo-700 hover:underline focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:text-indigo-400 dark:focus:ring-indigo-800">{{ __('auth.forgot') }}</a>
        <a href="{{ route('recovery.questions') }}" wire:navigate class="font-medium text-indigo-700 hover:underline focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:text-indigo-400 dark:focus:ring-indigo-800">{{ __('auth.recover_questions') }}</a>
    </div>
</x-ui.card>
