<?php

test('the application returns a successful response', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('Each agent gets a key and a brain. You edit the instructions.', false);
    $response->assertSee('Self-hosted Imp brain. One agent, one key, one file tree.', false);
    $response->assertSee('Shared folders. You set who can read and write. Not mail — that’s ImpPost.', false);
    $response->assertSee('navigator-brain', false);
    $response->assertSee('Not Mem0. Not ImpPost-mail. Not Melina. Not waifu chat.', false);
    $response->assertDontSee('deliveries', false);
    $response->assertDontSee('inbox', false);
});
