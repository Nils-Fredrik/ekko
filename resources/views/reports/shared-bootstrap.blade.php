<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow, noarchive"><meta name="referrer" content="no-referrer"><title>{{ __('reports.share.page') }}</title>@vite('resources/js/shared-bootstrap.js')</head>
<body>
<p id="status" data-not-found="{{ __('reports.share.not_found') }}">{{ __('reports.share.loading') }}</p>
</body>
</html>
