<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows each public page to guests', function (string $routeName, string $component) {
    $this->get(route($routeName))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'home' => ['home', 'welcome'],
    'features' => ['features', 'marketing/features'],
    'pricing' => ['pricing', 'marketing/pricing'],
    'security' => ['security', 'marketing/security'],
    'about' => ['about', 'marketing/about'],
    'contact' => ['contact', 'marketing/contact'],
    'terms' => ['terms', 'marketing/terms'],
    'privacy' => ['privacy', 'marketing/privacy'],
]);

it('shows public pages to signed-in users too', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('marketing/pricing'));
});
