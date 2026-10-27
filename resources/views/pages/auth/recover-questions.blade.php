<?php

use App\Actions\SecurityQuestionRecovery;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    #[Locked]
    public int $step = 1;

    #[Locked]
    public string $email = '';

    public string $emailInput = '';

    public string $answer_1 = '';

    public string $answer_2 = '';

    public string $answer_3 = '';

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'emailInput' => __('auth.email'),
            'answer_1' => __('settings.answer', ['n' => 1]),
            'answer_2' => __('settings.answer', ['n' => 2]),
            'answer_3' => __('settings.answer', ['n' => 3]),
        ];
    }

    /**
     * Always advances to the answers step with the same neutral outcome,
     * whether or not the email exists or has questions set.
     */
    public function lookup(): void
    {
        $this->validate(['emailInput' => ['required', 'email']]);

        $this->email = strtolower(trim($this->emailInput));
        $this->reset(['answer_1', 'answer_2', 'answer_3']);
        $this->step = 2;
    }

    /** @return list<string> */
    #[Computed]
    public function questions(): array
    {
        if ($this->step !== 2) {
            return [];
        }

        // The same predefined questions are shown for every email, whether
        // or not the account exists or has answers set.
        return SecurityQuestionRecovery::questions();
    }

    /**
     * Verify answers server-side (normalized, hashed compare) with attempt
     * and rate limiting. On success issue the shared single-use reset token
     * and hand off to the reset step.
     */
    public function verifyAnswers(SecurityQuestionRecovery $recovery): void
    {
        if ($this->step !== 2 || $this->email === '') {
            $this->backToEmail();

            return;
        }

        $ip = (string) request()->ip();
        $key = 'sec-verify:'.$this->email.'|'.$ip;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'answer_1' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::hit($key, 900);

        $this->validate([
            'answer_1' => ['required', 'string'],
            'answer_2' => ['required', 'string'],
            'answer_3' => ['required', 'string'],
        ]);

        $token = $recovery->verifyAndIssueToken($this->email, [
            'answer_1' => $this->answer_1,
            'answer_2' => $this->answer_2,
            'answer_3' => $this->answer_3,
        ]);

        RateLimiter::clear($key);

        session()->put('pwd_reset', ['email' => $this->email, 'token' => $token]);

        $this->redirectRoute('password.request', navigate: true);
    }

    public function backToEmail(): void
    {
        $this->reset(['email', 'emailInput', 'answer_1', 'answer_2', 'answer_3']);
        unset($this->questions);
        $this->step = 1;
    }
};
?>

<x-ui.card>
    <x-slot:header>
        <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ __('auth.recovery_title') }}</h1>
        <p class="mt-1 text-sm text-muted">{{ __('auth.questions_hint') }}</p>
    </x-slot:header>

    @if($step === 1)
        <form wire:submit="lookup" class="space-y-4">
            <x-ui.field :label="__('auth.email')" for="email" required :error="$errors->first('emailInput')">
                <x-ui.input id="email" type="email" wire:model.blur="emailInput" :error="$errors->first('emailInput')" autocomplete="email" required />
            </x-ui.field>
            <x-ui.button type="submit" variant="primary" class="w-full" loading-target="lookup" wire:loading.attr="disabled">{{ __('auth.lookup') }}</x-ui.button>
        </form>
    @else
        <form wire:submit="verifyAnswers" class="space-y-4">
            <x-ui.field :label="$this->questions[0]" for="answer_1" required :error="$errors->first('answer_1')">
                <x-ui.input id="answer_1" type="password" wire:model.blur="answer_1" :error="$errors->first('answer_1')" autocomplete="off" required />
            </x-ui.field>
            <x-ui.field :label="$this->questions[1]" for="answer_2" required :error="$errors->first('answer_2')">
                <x-ui.input id="answer_2" type="password" wire:model.blur="answer_2" :error="$errors->first('answer_2')" autocomplete="off" required />
            </x-ui.field>
            <x-ui.field :label="$this->questions[2]" for="answer_3" required :error="$errors->first('answer_3')">
                <x-ui.input id="answer_3" type="password" wire:model.blur="answer_3" :error="$errors->first('answer_3')" autocomplete="off" required />
            </x-ui.field>
            <x-ui.button type="submit" variant="primary" class="w-full" loading-target="verifyAnswers" wire:loading.attr="disabled">{{ __('auth.otp_verify') }}</x-ui.button>
        </form>
        <div class="mt-4 text-center text-sm">
            <x-ui.button variant="ghost" size="sm" wire:click="backToEmail" :spinner="false">{{ __('auth.back_to_email') }}</x-ui.button>
        </div>
    @endif

    <div class="mt-4 text-center text-sm">
        <a href="{{ route('login') }}" wire:navigate class="inline-flex min-h-11 items-center font-medium text-primary-text hover:underline focus:outline-none focus:ring-2 focus:ring-focus ">{{ __('auth.back_to_login') }}</a>
    </div>
</x-ui.card>
