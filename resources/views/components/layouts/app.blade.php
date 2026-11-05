<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ is_rtl() ? 'rtl' : 'ltr' }}" class="bg-page">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#FCFCFA">
    <title>Plate — Meal Booking</title>
    <meta name="description" content="Book one meal per day for the week ahead. Breakfast, lunch or dinner — before 14:00 the day before.">
    <link rel="icon" href="/icon-light-32x32.png" media="(prefers-color-scheme: light)">
    <link rel="icon" href="/icon-dark-32x32.png" media="(prefers-color-scheme: dark)">
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-icon.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased">
    {{ $slot }}
    <x-notification-popup />
</body>
</html>
