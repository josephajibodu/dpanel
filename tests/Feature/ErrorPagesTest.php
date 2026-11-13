<?php

use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['app.debug' => false]);

    Route::middleware('web')->group(function () {
        Route::get('/_errors/forbidden', fn () => abort(403, 'Your plan does not include backups.'));
        Route::post('/_errors/forbidden', fn () => abort(403, 'Your plan does not include backups.'));
        Route::get('/_errors/crash', fn () => throw new RuntimeException('Connection refused for db_password=secret'));
        Route::post('/_errors/crash', fn () => throw new RuntimeException('Connection refused for db_password=secret'));
        Route::post('/_errors/validate', fn () => request()->validate(['name' => 'required']));
        Route::post('/_errors/{status}', fn (int $status) => abort($status));
    });
});

it('renders the error page when a page fails to load', function () {
    $response = $this->get('/_errors/crash');

    $response->assertInternalServerError()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('error-page')
            ->where('status', 500)
            ->where('message', '')
        );
});

it('renders the error page for an address that does not exist', function () {
    $response = $this->get('/no-such-page');

    $response->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('error-page')
            ->where('status', 404)
        );
});

it('shows the message of a 403 on the error page', function () {
    $response = $this->get('/_errors/forbidden');

    $response->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('error-page')
            ->where('message', 'Your plan does not include backups.')
        );
});

it('sends a failed form submit back to its page with an error toast', function () {
    $response = $this->from('/settings/profile')
        ->withHeader('X-Inertia', 'true')
        ->post('/_errors/crash');

    $response->assertStatus(303)
        ->assertRedirect('/settings/profile')
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Something went wrong on our end. Please try again.',
        ]);
});

it('keeps the message of a 403 in the toast', function () {
    $response = $this->from('/settings/profile')
        ->withHeader('X-Inertia', 'true')
        ->post('/_errors/forbidden');

    $response->assertRedirect('/settings/profile')
        ->assertInertiaFlash('toast.message', 'Your plan does not include backups.');
});

it('explains each kind of failed form submit in the toast', function (int $status, string $message) {
    $response = $this->from('/settings/profile')
        ->withHeader('X-Inertia', 'true')
        ->post("/_errors/{$status}");

    $response->assertRedirect('/settings/profile')
        ->assertInertiaFlash('toast.message', $message);
})->with([
    '403 without a message' => [403, "You don't have permission to do that."],
    '404' => [404, "We couldn't find that. It may have been deleted."],
    '419' => [419, 'Your session timed out. Please try again.'],
    '429' => [429, 'Too many attempts. Wait a minute and try again.'],
    '503' => [503, "We're doing some quick maintenance. Please try again in a few minutes."],
]);

it('renders the error page for a form submit that fails before the session starts', function () {
    $response = $this->withHeader('X-Inertia', 'true')->post('/no-such-page');

    $response->assertNotFound()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'error-page')
        ->assertJsonPath('props.status', 404);
});

it('still sends validation errors back to the form', function () {
    $response = $this->from('/settings/profile')
        ->withHeader('X-Inertia', 'true')
        ->post('/_errors/validate');

    $response->assertRedirect('/settings/profile')
        ->assertSessionHasErrors('name')
        ->assertInertiaFlashMissing('toast');
});

it('leaves JSON errors as JSON', function () {
    $response = $this->getJson('/no-such-page');

    $response->assertNotFound()
        ->assertHeaderMissing('X-Inertia')
        ->assertJsonStructure(['message']);
});

it('leaves failed posts from outside the app as plain errors', function () {
    $response = $this->post('/_errors/crash');

    $response->assertInternalServerError()
        ->assertHeaderMissing('X-Inertia')
        ->assertDontSee('error-page');
});

it('keeps the detailed error page while debug is on', function () {
    config(['app.debug' => true]);

    $response = $this->withHeader('X-Inertia', 'true')
        ->post('/_errors/crash');

    $response->assertInternalServerError()
        ->assertHeaderMissing('X-Inertia');
});
