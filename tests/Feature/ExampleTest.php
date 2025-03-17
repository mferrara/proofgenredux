<?php

it('returns a successful response', function () {
    $response = $this->get('/test');

    $response->assertStatus(200);
});
