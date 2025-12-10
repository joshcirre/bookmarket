<?php

use App\Models\User;

test('guests are redirected to the login page', function (): void {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('authenticated users are redirected to lists', function (): void {
    $this->actingAs($user = User::factory()->create());

    $this->get('/dashboard')->assertRedirect('/lists');
});
