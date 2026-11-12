<?php
    // The headline is the greeting if one is set, otherwise the subject. A
    // subject such as "Site down: shop.example.com" is split at the colon so
    // what it is about shows in the lighter second tone, as on the website.
    $title = ! empty($greeting) ? $greeting : ($subject ?? config('app.name'));
    [$headline, $accent] = str_contains($title, ': ')
        ? [Illuminate\Support\Str::before($title, ': ').'.', Illuminate\Support\Str::after($title, ': ')]
        : [$title, null];
?>
<x-mail::message :headline="$headline" :accent="$accent" :status="$level">
{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
@lang('Button not working? Copy and paste this link into your browser:') <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
