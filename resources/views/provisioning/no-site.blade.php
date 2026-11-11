@extends('provisioning.layout')

@section('title', 'Nothing deployed here yet')

@section('content')
        <p class="status">
            <span class="status-code">404</span>
            <span class="status-host">{!! $host !!}</span>
        </p>

        <h1>Nothing deployed here. <span>Yet.</span></h1>

        <p class="lead">This server is up and running, but none of its sites are set up to answer at this address.</p>

        <section class="owner">
            <h2>Is this your server?</h2>
            <p>Create a site for this domain in flitops, or add the domain to one of your existing sites. As soon as it's saved, visitors will see your site instead of this page.</p>
        </section>

        <div class="actions">
            <a class="button button-primary" href="https://flitops.com">
                Open flitops
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            </a>
            <a class="button button-secondary" href="https://docs.flitops.com/sites/creating-sites">How to add a site</a>
        </div>
@endsection

@section('footer')
This server is managed with <a href="https://flitops.com">flitops</a>.
@endsection
