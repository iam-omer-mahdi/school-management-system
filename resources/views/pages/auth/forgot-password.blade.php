<?php

use App\Actions\RequestPasswordOtp;
use App\Mail\ResetCodeMail;
use App\Services\PasswordResetService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
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

    public string $code = '';

    public string $password = '';

    public string $password_confirmation = '';

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'emailInput' => __('auth.email'),
            'code' => __('auth.otp_code'),
            'password' => __('auth.new_password'),
        ];
    }

    public function mount(): void
    {
        $pending = session()->get('pwd_reset');

        if (is_array($pending) && isset($pending['email'], $pending['token'])) {
            $valid = app(PasswordResetService::class)->findValidToken(
                (string) $pending['email'],
                (string) $pending['token'],
            );

            if ($valid !== null) {
                $this->email = (string) $pending['email'];
                $this->step = 3;

                return;
            }

            session()->forget('pwd_reset');
        }

        $this->step = 1;
    }

    #[Computed]
    public function resendIn(): int
    {
        $sentAt = session()->get('pwd_reset_sent_at');

        if (! is_int($sentAt)) {
            return 0;
        }

        return max(0, 60 - (time() - $sentAt));
    }

    /**
     * Step 1: request an OTP. Always shows the same neutral message and moves
     * to the code step, whether or not the email exists.
     */
    public function sendCode(RequestPasswordOtp $action): void
    {
        $this->validate(['emailInput' => ['required', 'email']]);

        $address = strtolower(trim($this->emailInput));
        $ip = (string) request()->ip();

        $minuteKey = 'pwd-send:'.$address.'|'.$ip;
        if (RateLimiter::tooManyAttempts($minuteKey, 1)) {
            throw ValidationException::withMessages([
                'emailInput' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($minuteKey)]),
            ]);
        }

        $hourKey = 'pwd-send-hour:'.$address.'|'.$ip;
        if (RateLimiter::tooManyAttempts($hourKey, 5)) {
            throw ValidationException::withMessages([
                'emailInput' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($hourKey)]),
            ]);
        }

        RateLimiter::hit($minuteKey, 60);
        RateLimiter::hit($hourKey, 3600);

        $code = $action->request($address);

        if ($code !== null) {
            try {
                Mail::to($address)->send(new ResetCodeMail($code, RequestPasswordOtp::OTP_TTL_MINUTES));
            } catch (Throwable $e) {
                Log::error('Password reset mail failed.', ['email' => $address, 'error' => $e->getMessage()]);
                $this->addError('emailInput', __('auth.otp_send_failed'));

                return;
            }
        }

        $this->email = $address;
        $this->step = 2;
        $this->reset(['code', 'password', 'password_confirmation']);
        session()->put('pwd_reset_sent_at', time());
        unset($this->resendIn);
        session()->flash('neutral', __('auth.otp_neutral'));
    }

    public function resend(RequestPasswordOtp $action): void
    {
        $this->sendCode($action);
    }

    /**
     * Step 2: verify the OTP. Only on server-side success do we issue the
     * single-use reset token (kept in the session) and move to step 3.
     */
    public function verifyCode(RequestPasswordOtp $action, PasswordResetService $tokens): void
    {
        if ($this->step !== 2 || $this->email === '') {
            $this->backToEmail();

            return;
        }

        $ip = (string) request()->ip();
        $key = 'pwd-verify:'.$this->email.'|'.$ip;

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([
                'code' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::hit($key, 600);

        $this->validate(['code' => ['required', 'string', 'size:6']]);

        $action->verify($this->email, trim($this->code));

        $token = $action->issueResetToken($this->email);

        session()->put('pwd_reset', ['email' => $this->email, 'token' => $token]);
        $this->step = 3;
        $this->reset(['code']);
        session()->flash('verified', __('auth.otp_verified'));
    }

    /**
     * Step 3: reachable only with a valid session token. The token is checked
     * here again on submit, never trusted from the client.
     */
    public function resetPassword(PasswordResetService $tokens): void
    {
        $pending = session()->get('pwd_reset');

        if ($this->step !== 3 || ! is_array($pending) || ! isset($pending['email'], $pending['token'])) {
            $this->backToEmail();

            return;
        }

        $rules = ['required', 'string', 'min:8', 'confirmed'];
        $defaults = Password::defaults();
        if ($defaults !== null) {
            $rules[] = $defaults;
        }

        $this->validate(['password' => $rules]);

        try {
            $tokens->completeReset((string) $pending['email'], (string) $pending['token'], $this->password);
        } catch (ValidationException $e) {
            $this->backToEmail();

            throw $e;
        }

        session()->forget(['pwd_reset', 'pwd_reset_sent_at']);
        session()->regenerate();
        session()->flash('status', __('auth.reset_done'));

        $this->redirectRoute('login', navigate: true);
    }

    public function backToEmail(): void
    {
        session()->forget(['pwd_reset', 'pwd_reset_sent_at']);
        $this->reset(['email', 'emailInput', 'code', 'password', 'password_confirmation']);
        $this->step = 1;
    }
};
?>

<x-ui.card>
    <x-slot:header>
        <h1 class="text-xl font-semibold text-gray-900">{{ __('auth.otp_title') }}</h1>
        <p class="mt-1 text-sm text-gray-600">{{ __('auth.otp_hint') }}</p>
    </x-slot:header>

    @if($step === 1)
        <form wire:submit="sendCode" class="space-y-4">
            <x-ui.field :label="__('auth.email')" for="email" required :error="$errors->first('emailInput')">
                <x-ui.input id="email" type="email" wire:model.blur="emailInput" :error="$errors->first('emailInput')" autocomplete="email" required />
            </x-ui.field>
            <x-ui.button type="submit" variant="primary" class="w-full" loading-target="sendCode" wire:loading.attr="disabled">{{ __('auth.otp_request') }}</x-ui.button>
        </form>
        <div class="mt-4 text-center text-sm">
            <a href="{{ route('recovery.questions') }}" wire:navigate class="font-medium text-indigo-700 hover:underline focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:text-indigo-400 dark:focus:ring-indigo-800">{{ __('auth.use_questions') }}</a>
        </div>
    @elseif($step === 2)
        @if(session('neutral'))
            <div class="mb-4"><x-ui.alert type="info">{{ session('neutral') }}</x-ui.alert></div>
        @endif
        <form wire:submit="verifyCode" class="space-y-4">
            <x-ui.field :label="__('auth.otp_code')" for="code" required :error="$errors->first('code')">
                <x-ui.input id="code" type="text" inputmode="numeric" wire:model.blur="code" :error="$errors->first('code')" autocomplete="one-time-code" required />
            </x-ui.field>
            <x-ui.button type="submit" variant="primary" class="w-full" loading-target="verifyCode" wire:loading.attr="disabled">{{ __('auth.otp_verify') }}</x-ui.button>
        </form>
        <div class="mt-4 space-y-2 text-center text-sm" wire:poll.15s>
            @if($this->resendIn > 0)
                <p class="text-gray-500 dark:text-gray-400">{{ __('auth.otp_resend_in', ['seconds' => $this->resendIn]) }}</p>
            @else
                <x-ui.button variant="ghost" size="sm" wire:click="resend" loading-target="resend" wire:loading.attr="disabled">{{ __('auth.otp_resend') }}</x-ui.button>
            @endif
            <div>
                <x-ui.button variant="ghost" size="sm" wire:click="backToEmail" :spinner="false">{{ __('auth.back_to_email') }}</x-ui.button>
            </div>
        </div>
    @else
        @if(session('verified'))
            <div class="mb-4"><x-ui.alert type="success">{{ session('verified') }}</x-ui.alert></div>
        @endif
        <form wire:submit="resetPassword" class="space-y-4">
            <h2 class="text-base font-semibold text-gray-900">{{ __('auth.reset_title') }}</h2>
            <x-ui.field :label="__('auth.new_password')" for="password" required :error="$errors->first('password')">
                <x-ui.input id="password" type="password" wire:model.blur="password" :error="$errors->first('password')" autocomplete="new-password" required />
            </x-ui.field>
            <x-ui.field :label="__('auth.new_password_confirmation')" for="password_confirmation">
                <x-ui.input id="password_confirmation" type="password" wire:model.blur="password_confirmation" autocomplete="new-password" />
            </x-ui.field>
            <x-ui.button type="submit" variant="primary" class="w-full" loading-target="resetPassword" wire:loading.attr="disabled">{{ __('auth.otp_verify') }}</x-ui.button>
        </form>
    @endif

    <div class="mt-4 text-center text-sm">
        <a href="{{ route('login') }}" wire:navigate class="font-medium text-indigo-700 hover:underline focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:text-indigo-400 dark:focus:ring-indigo-800">{{ __('auth.back_to_login') }}</a>
    </div>
</x-ui.card>
