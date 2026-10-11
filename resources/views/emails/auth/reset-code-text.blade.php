{{ __('auth.otp_email_greeting') }}

{{ __('auth.otp_email_body', ['code' => $code, 'minutes' => $minutes]) }}

{{ __('auth.otp_email_ignore') }}
