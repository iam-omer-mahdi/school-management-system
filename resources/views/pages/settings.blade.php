<?php

use App\Actions\RequestEmailChangeOtp;
use App\Actions\SecurityQuestionRecovery;
use App\Mail\ResetCodeMail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    public string $new_email = '';

    public string $email_code = '';

    #[Locked]
    public string $pending_email = '';

    public bool $showEmailForm = false;

    public bool $emailCodeSent = false;

    public string $current_password = '';

    public string $new_password = '';

    public string $new_password_confirmation = '';

    public bool $showPasswordForm = false;

    public string $answer_1 = '';

    public string $answer_2 = '';

    public string $answer_3 = '';

    public bool $showSecurityForm = false;

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'new_email' => __('settings.new_email'),
            'email_code' => __('settings.email_code'),
            'current_password' => __('settings.current_password'),
            'new_password' => __('settings.password'),
            'answer_1' => __('settings.answer', ['n' => 1]),
            'answer_2' => __('settings.answer', ['n' => 2]),
            'answer_3' => __('settings.answer', ['n' => 3]),
        ];
    }

    /**
     * Step 1: send a verification code to the new address to prove ownership
     * before anything changes.
     */
    public function sendEmailCode(RequestEmailChangeOtp $action): void
    {
        $user = auth()->user();

        $this->validate([
            'new_email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $address = strtolower(trim($this->new_email));

        if ($address === strtolower((string) $user->email)) {
            $this->addError('new_email', __('settings.email_unchanged'));

            return;
        }

        $ip = (string) request()->ip();
        $minuteKey = 'email-change-send:'.$user->id.'|'.$ip;
        if (RateLimiter::tooManyAttempts($minuteKey, 1)) {
            throw ValidationException::withMessages([
                'new_email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($minuteKey)]),
            ]);
        }

        $hourKey = 'email-change-send-hour:'.$user->id.'|'.$ip;
        if (RateLimiter::tooManyAttempts($hourKey, 5)) {
            throw ValidationException::withMessages([
                'new_email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($hourKey)]),
            ]);
        }

        RateLimiter::hit($minuteKey, 60);
        RateLimiter::hit($hourKey, 3600);

        $code = $action->request($user, $address);

        try {
            Mail::to($address)->send(new ResetCodeMail($code, RequestEmailChangeOtp::OTP_TTL_MINUTES, __('settings.email_code_subject')));
        } catch (Throwable $e) {
            Log::error('Email change mail failed.', ['email' => $address, 'error' => $e->getMessage()]);
            $this->addError('new_email', __('settings.email_code_send_failed'));

            return;
        }

        $this->pending_email = $address;
        $this->emailCodeSent = true;
        $this->reset('email_code');
    }

    public function resendEmailCode(RequestEmailChangeOtp $action): void
    {
        $this->sendEmailCode($action);
    }

    /**
     * Step 2: verify the code. Only on server-side success does the user's
     * email change (inside the action, against the locked pending address).
     */
    public function verifyEmailCode(RequestEmailChangeOtp $action): void
    {
        if (! $this->emailCodeSent || $this->pending_email === '') {
            $this->cancelEmailChange();

            return;
        }

        $user = auth()->user();
        $ip = (string) request()->ip();
        $key = 'email-change-verify:'.$user->id.'|'.$ip;

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([
                'email_code' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::hit($key, 600);

        $this->validate(['email_code' => ['required', 'string', 'size:6']]);

        $action->verify($user, $this->pending_email, trim($this->email_code));

        RateLimiter::clear($key);

        $this->reset(['new_email', 'email_code', 'pending_email']);
        $this->emailCodeSent = false;
        $this->showEmailForm = false;

        session()->flash('email', __('settings.email_updated'));
    }

    public function cancelEmailChange(): void
    {
        $this->reset(['new_email', 'email_code', 'pending_email']);
        $this->emailCodeSent = false;
        $this->showEmailForm = false;
    }

    public function updatePassword(): void
    {
        $user = auth()->user();

        $this->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($this->current_password, (string) $user->password)) {
            $this->addError('current_password', __('auth.failed'));

            return;
        }

        $user->password = $this->new_password;
        $user->save();

        $this->reset(['current_password', 'new_password', 'new_password_confirmation']);
        $this->showPasswordForm = false;

        session()->flash('password', __('settings.password_updated'));
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

<div class="space-y-8 lg:space-y-10">
    <x-ui.page-header :title="__('settings.title')" />

    <x-ui.card :title="__('settings.email_title')">
        @if(session('email'))
            <div class="mb-4"><x-ui.alert type="success" dismissible>{{ session('email') }}</x-ui.alert></div>
        @endif
        <x-ui.field :label="__('settings.current_email')" for="current-email">
            <p id="current-email" class="rounded-sm border border-line bg-surface-raised p-2.5 text-sm text-ink ">{{ auth()->user()->email }}</p>
        </x-ui.field>
        <div class="mt-4">
            <x-ui.button type="button" variant="secondary" size="sm" wire:click="$toggle('showEmailForm')" :spinner="false">
                {{ $showEmailForm ? __('settings.hide_email_form') : __('settings.change_email') }}
            </x-ui.button>
        </div>
        @if($showEmailForm)
            @if(! $emailCodeSent)
                <form wire:submit="sendEmailCode" class="mt-4 space-y-4">
                    <x-ui.field :label="__('settings.new_email')" for="new_email" required :error="$errors->first('new_email')">
                        <x-ui.input id="new_email" type="email" wire:model.blur="new_email" :error="$errors->first('new_email')" autocomplete="email" required />
                    </x-ui.field>
                    <x-ui.button type="submit" variant="primary" loading-target="sendEmailCode" wire:loading.attr="disabled">{{ __('settings.send_code') }}</x-ui.button>
                </form>
            @else
                <div class="mt-4"><x-ui.alert type="info">{{ __('settings.email_code_hint') }}</x-ui.alert></div>
                <form wire:submit="verifyEmailCode" class="mt-4 space-y-4">
                    <x-ui.field :label="__('settings.email_code')" for="email_code" required :error="$errors->first('email_code')">
                        <x-ui.input id="email_code" type="text" inputmode="numeric" wire:model.blur="email_code" :error="$errors->first('email_code')" autocomplete="one-time-code" required />
                    </x-ui.field>
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="submit" variant="primary" loading-target="verifyEmailCode" wire:loading.attr="disabled">{{ __('settings.verify_email') }}</x-ui.button>
                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="resendEmailCode" loading-target="resendEmailCode" wire:loading.attr="disabled">{{ __('settings.email_resend') }}</x-ui.button>
                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="cancelEmailChange" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                    </div>
                </form>
            @endif
        @endif
    </x-ui.card>

    <x-ui.card :title="__('settings.password_title')">
        @if(session('password'))
            <div class="mb-4"><x-ui.alert type="success" dismissible>{{ session('password') }}</x-ui.alert></div>
        @endif
        <x-ui.button type="button" variant="secondary" size="sm" wire:click="$toggle('showPasswordForm')" :spinner="false">
            {{ $showPasswordForm ? __('settings.hide_password_form') : __('settings.change_password') }}
        </x-ui.button>
        @if($showPasswordForm)
            <form wire:submit="updatePassword" class="mt-4 space-y-4">
                <x-ui.field :label="__('settings.current_password')" for="current_password" required :error="$errors->first('current_password')">
                    <x-ui.input id="current_password" type="password" wire:model.blur="current_password" :error="$errors->first('current_password')" autocomplete="current-password" required />
                </x-ui.field>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('settings.password')" for="new_password" required :error="$errors->first('new_password')">
                        <x-ui.input id="new_password" type="password" wire:model.blur="new_password" :error="$errors->first('new_password')" autocomplete="new-password" required />
                    </x-ui.field>
                    <x-ui.field :label="__('settings.password_confirmation')" for="new_password_confirmation" required>
                        <x-ui.input id="new_password_confirmation" type="password" wire:model.blur="new_password_confirmation" autocomplete="new-password" required />
                    </x-ui.field>
                </div>
                <x-ui.button type="submit" variant="primary" loading-target="updatePassword" wire:loading.attr="disabled">{{ __('common.save') }}</x-ui.button>
            </form>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('settings.security')" :description="__('settings.security_hint')">
        @if(session('security'))
            <div class="mb-4"><x-ui.alert type="success" dismissible>{{ session('security') }}</x-ui.alert></div>
        @endif
        <x-ui.button type="button" variant="secondary" size="sm" wire:click="$toggle('showSecurityForm')" :spinner="false">
            {{ $showSecurityForm ? __('settings.hide_security_form') : __('settings.manage_security') }}
        </x-ui.button>
        @if($showSecurityForm)
            <div class="mt-4"><x-ui.alert type="info">{{ __('settings.security_invented_hint') }}</x-ui.alert></div>
            @php($securityQuestions = SecurityQuestionRecovery::questions())
            <form wire:submit="updateSecurity" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="text-sm font-medium text-ink ">{{ $securityQuestions[0] }}</div>
                    <x-ui.field :label="__('settings.answer', ['n' => 1])" for="answer_1" required :error="$errors->first('answer_1')">
                        <x-ui.input id="answer_1" type="password" wire:model.blur="answer_1" :error="$errors->first('answer_1')" autocomplete="off" required />
                    </x-ui.field>
                    <div class="text-sm font-medium text-ink ">{{ $securityQuestions[1] }}</div>
                    <x-ui.field :label="__('settings.answer', ['n' => 2])" for="answer_2" required :error="$errors->first('answer_2')">
                        <x-ui.input id="answer_2" type="password" wire:model.blur="answer_2" :error="$errors->first('answer_2')" autocomplete="off" required />
                    </x-ui.field>
                    <div class="text-sm font-medium text-ink ">{{ $securityQuestions[2] }}</div>
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
                <p class="mb-2 text-sm font-medium text-ink ">{{ __('settings.language_title') }}</p>
                <form method="POST" action="{{ route('locale.switch') }}" class="flex flex-wrap gap-2">
                    @csrf
                    <x-ui.button type="submit" name="locale" value="en" variant="secondary" size="sm" :spinner="false">{{ __('common.english') }}</x-ui.button>
                    <x-ui.button type="submit" name="locale" value="ar" variant="secondary" size="sm" :spinner="false">{{ __('common.arabic') }}</x-ui.button>
                </form>
            </div>
            <div>
                <p class="mb-2 text-sm font-medium text-ink ">{{ __('settings.appearance_title') }}</p>
                <p class="mb-2 text-xs text-muted ">{{ __('settings.appearance_hint') }}</p>
                <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('settings.appearance_title') }}">
                    <x-ui.button type="button" variant="secondary" size="sm" data-theme-set="light" :spinner="false">{{ __('settings.theme_light') }}</x-ui.button>
                    <x-ui.button type="button" variant="secondary" size="sm" data-theme-set="dark" :spinner="false">{{ __('settings.theme_dark') }}</x-ui.button>
                </div>
            </div>
        </div>
    </x-ui.card>
</div>
