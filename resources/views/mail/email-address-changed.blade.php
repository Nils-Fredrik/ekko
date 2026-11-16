@component('mail::message')
{{ __('auth.email_changed.greeting', ['name' => $safeName]) }}

{{ __('auth.email_changed.line', ['email' => $safeEmail]) }}

{{ __('auth.email_changed.not_you') }}
@endcomponent
