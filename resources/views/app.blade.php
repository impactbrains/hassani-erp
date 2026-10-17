<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ur' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1C2B4A">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <title inertia>{{ config('app.name', 'Hassani ERP') }}</title>
    @routes
    @vite(['resources/js/app.ts'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
