<?php

use Inertia\Testing\AssertableInertia;

it('renders the landing page', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('welcome')
            ->has('canRegister'));
});
