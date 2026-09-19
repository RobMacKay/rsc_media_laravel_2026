<?php

test('the old welcome page sends visitors to the home page', function () {
    $this->get('/welcome-to-rsc-media')
        ->assertStatus(301)
        ->assertRedirect(route('home'));
});

test('the old welcome page redirects with its trailing slash and query string', function () {
    $this->get('/welcome-to-rsc-media/?m=a')
        ->assertStatus(301)
        ->assertRedirect(route('home'));
});
