@props([
    'headline' => null,
    'accent' => null,
    'status' => null,
    'lead' => null,
    'actionText' => null,
    'actionUrl' => null,
])
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')" :headline="$headline" :accent="$accent" :status="$status" :lead="$lead" :action-text="$actionText" :action-url="$actionUrl" />
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
Sent by [Flitops]({{ config('app.url') }}), servers deployed like a platform.

© {{ date('Y') }} Flitops. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
