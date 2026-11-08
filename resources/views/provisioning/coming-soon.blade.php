@extends('provisioning.layout')

@section('title', 'Coming soon')

@section('content')
        <p class="status">
            <span class="status-code">Soon</span>
            <span>Waiting for its first deploy</span>
        </p>

        <h1>Coming soon. <span>Stay tuned.</span></h1>

        <p class="lead">This site has been set up, but nothing has been published to it yet.</p>

        <section class="owner">
            <h2>Is this your site?</h2>
            <p>Deploy your code from the site's page in flitops. As soon as the first deploy finishes, visitors will see your site instead of this page.</p>
        </section>

        <div class="actions">
            <a class="button button-primary" href="https://flitops.com">
                Open flitops
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            </a>
            <a class="button button-secondary" href="https://docs.flitops.com/sites/deployments">How deploys work</a>
        </div>
@endsection

@section('footer')
This site is hosted with <a href="https://flitops.com">flitops</a>.
@endsection
