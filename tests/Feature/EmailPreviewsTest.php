<?php

use App\Support\EmailPreviews;

test('every email preview renders with the flitops layout', function () {
    $keys = array_keys(EmailPreviews::all());

    expect($keys)->not->toBeEmpty();

    foreach ($keys as $key) {
        expect((string) EmailPreviews::message($key)->render())
            ->toContain('class="wordmark"', 'Sent by');
    }
});

test('an unknown email has no preview', function () {
    expect(EmailPreviews::message('not-an-email'))->toBeNull();
});

test('the email preview page does not exist outside a local machine', function () {
    $this->get('/dev/emails')->assertNotFound();
    $this->get('/dev/emails/welcome')->assertNotFound();
});
