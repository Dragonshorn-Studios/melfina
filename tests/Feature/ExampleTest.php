<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Each agent gets a key and a brain. You edit the instructions.', false);
        $response->assertSee('Self-hosted Imp brain. One agent, one key, one file tree.', false);
        $response->assertSee('Shared folders. You set who can read and write. Not mail — that’s ImpPost.', false);
        $response->assertSee('navigator-brain', false);
        $response->assertSee('Not Mem0. Not ImpPost-mail. Not Melina. Not waifu chat.', false);
        $response->assertDontSee('deliveries', false);
        $response->assertDontSee('inbox', false);
    }
}
