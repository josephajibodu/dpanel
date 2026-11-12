<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
{{-- The site's fonts. Apple Mail and iOS load them; other clients fall back to system fonts. --}}
<link href="https://fonts.bunny.net/css?family=inter:400,500,600|host-grotesk:400,500|jetbrains-mono:400" rel="stylesheet" />
<style>
@media only screen and (max-width: 600px) {
.inner-body,
.footer {
width: 100% !important;
}

.wrapper {
padding: 16px 8px 0 !important;
}

.hero-cell {
padding: 28px 24px 32px !important;
}

.hero-brand {
padding-bottom: 32px !important;
}

.headline {
font-size: 28px !important;
line-height: 32px !important;
}

.content-cell {
padding: 28px 24px !important;
}
}

@media only screen and (max-width: 500px) {
.button,
.hero-button {
display: block !important;
text-align: center !important;
}

.step-number {
width: 36px !important;
}
}
</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="inner-body" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
{{-- Blue header with the site's grid, holding the logo and headline --}}
<tr>
<td class="hero" bgcolor="#2563eb" style="background-image: url('{{ asset('images/email/grid.png') }}');">
{!! $header ?? '' !!}
</td>
</tr>

<!-- Body content -->
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>

{!! $footer ?? '' !!}
</td>
</tr>
</table>
</body>
</html>
