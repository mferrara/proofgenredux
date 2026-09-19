<?php

it('serves the login page to a visitor who is not signed in', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});
