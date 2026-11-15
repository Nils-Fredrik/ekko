@component('mail::message')
# {{ trans_choice('alerts.mail.heading', $count, ['tenant' => $safeTenant, 'count' => $count]) }}

@foreach ($safeGroups as $group)
**{{ $group['title'] }}**

@foreach ($group['lines'] as $line)
- {{ $line }}
@endforeach

@endforeach
@component('mail::button', ['url' => $url])
{{ __('alerts.mail.open') }}
@endcomponent
@endcomponent
