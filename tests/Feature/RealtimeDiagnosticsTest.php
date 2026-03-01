<?php

use App\Events\RealtimeDiagnosticMessage;
use App\Models\User;
use App\Realtime\RealtimeDiagnosticsChannelAuthorizer;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;

it('allows authenticated users to access the realtime diagnostics page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('realtime.test'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('realtime/test')
            ->where('user.id', $user->id)
            ->where('channel', "realtime.diagnostics.{$user->id}")
            ->has('reverb')
        );
});

it('redirects guests away from realtime diagnostics page', function () {
    $response = $this->get(route('realtime.test'));

    $response->assertRedirect(route('login'));
});

it('dispatches realtime diagnostic event from trigger endpoint', function () {
    Event::fake([RealtimeDiagnosticMessage::class]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('realtime.test.trigger'), [
        'message' => 'Diagnostics smoke test',
    ]);

    $response->assertNoContent();

    Event::assertDispatched(RealtimeDiagnosticMessage::class, function (RealtimeDiagnosticMessage $event) use ($user) {
        return $event->userId === $user->id
            && $event->message === 'Diagnostics smoke test'
            && $event->eventId !== '';
    });
});

it('authorizes diagnostics channel when requested user matches authenticated user', function () {
    $user = User::factory()->create();
    $authorizer = app(RealtimeDiagnosticsChannelAuthorizer::class);

    expect($authorizer->canAccess($user, $user->id))->toBeTrue();
});

it('denies diagnostics channel when requested user does not match authenticated user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $authorizer = app(RealtimeDiagnosticsChannelAuthorizer::class);

    expect($authorizer->canAccess($otherUser, $user->id))->toBeFalse();
});
