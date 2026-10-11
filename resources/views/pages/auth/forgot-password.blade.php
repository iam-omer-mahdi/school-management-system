<?php

use App\Actions\RequestPasswordOtp;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    public string $email = '';

    public string $code = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $sent = false;

    public bool $done = false;

    public function requestCode(RequestPasswordOtp $action): void
    {
        $this->validate(['email' => ['required', 'email']]);

        $action->handle($this->email);

        $this->sent = true;
    }

    public function resetPassword(RequestPasswordOtp $action): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'size:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $action->resetWithOtp($this->email, $this->code, $this->password);

        $this->done = true;
    }
};
?>

<div class="rounded bg-white p-6 shadow">
    <h1 class="mb-2 text-xl font-semibold">{{ __('auth.otp_title') }}</h1>
    <p class="mb-4 text-sm text-gray-600">{{ __('auth.otp_hint') }}</p>

    @if($done)
        <p class="rounded bg-green-100 p-3 text-sm text-green-800">{{ __('auth.reset_done') }}</p>
        <a href="{{ route('login') }}" wire:navigate class="mt-3 inline-block text-blue-700 hover:underline">{{ __('auth.title') }}</a>
    @else
        <form wire:submit="requestCode" class="mb-4 space-y-3">
            <x-field :label="__('auth.email')" :error="$errors->first('email')">
                <input type="email" wire:model.blur="email" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
            <button type="submit" wire:loading.attr="disabled" class="w-full rounded bg-gray-900 px-3 py-2 text-white hover:bg-gray-700">{{ __('auth.otp_request') }}</button>
            @if($sent)
                <p class="text-sm text-green-700">{{ __('auth.otp_sent') }}</p>
            @endif
        </form>

        <form wire:submit="resetPassword" class="space-y-3 border-t pt-4">
            <x-field :label="__('auth.otp_code')" :error="$errors->first('code')">
                <input type="text" inputmode="numeric" wire:model.blur="code" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
            <x-field :label="__('auth.new_password')" :error="$errors->first('password')">
                <input type="password" wire:model.blur="password" class="w-full rounded border-gray-300 px-3 py-2" autocomplete="new-password" />
            </x-field>
            <x-field :label="__('auth.new_password_confirmation')">
                <input type="password" wire:model.blur="password_confirmation" class="w-full rounded border-gray-300 px-3 py-2" autocomplete="new-password" />
            </x-field>
            <button type="submit" wire:loading.attr="disabled" class="w-full rounded bg-gray-900 px-3 py-2 text-white hover:bg-gray-700">{{ __('auth.otp_verify') }}</button>
        </form>
    @endif
</div>
