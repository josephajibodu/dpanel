<x-mail::message
    :headline="'Welcome to Flitops'.($firstName !== '' ? ', '.$firstName : '').'.'"
    accent="Let's ship your first site."
    lead="Your email is confirmed and your account is ready. Connect a cloud account and you can have a site live in the next ten minutes."
    action-text="Create your first server"
    :action-url="$actionUrl"
>
## What you can do

<table class="steps" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($steps as $step)
<tr>
<td class="step-number">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</td>
<td class="step-body">
<p class="step-title">{{ $step['title'] }}</p>
<p class="step-text">{{ $step['text'] }}</p>
</td>
</tr>
@endforeach
</table>

Need a hand getting started? [Get in touch]({{ route('contact') }}) and we'll help.
</x-mail::message>
