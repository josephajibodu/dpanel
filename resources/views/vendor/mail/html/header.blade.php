@props([
    'url',
    'headline' => null,
    'accent' => null,
    'status' => null,
    'lead' => null,
    'actionText' => null,
    'actionUrl' => null,
])
<?php
    $statusLabel = match ($status) {
        'error' => __('Needs attention'),
        'warning' => __('Heads up'),
        'success' => __('Resolved'),
        default => null,
    };
?>
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="hero-cell">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="hero-brand">
<a href="{{ $url }}" class="brand">
<img src="{{ asset('images/email/logo-mark-white.png') }}" class="logo" width="20" height="22" alt="">
<span class="wordmark">flitops</span>
</a>
</td>
</tr>
</table>
@if ($statusLabel)
<span class="status"><span class="status-dot status-dot-{{ $status }}">&#9679;</span>&nbsp;{{ $statusLabel }}</span>
@endif
@if ($headline)
<h1 class="headline">{{ $headline }}@if ($accent) <span class="headline-accent">{{ $accent }}</span>@endif</h1>
@endif
@if ($lead)
<p class="lead">{{ $lead }}</p>
@endif
@if ($actionText && $actionUrl)
<table cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="hero-action">
<a href="{{ $actionUrl }}" class="hero-button" target="_blank" rel="noopener">{{ $actionText }}</a>
</td>
</tr>
</table>
@endif
</td>
</tr>
</table>
