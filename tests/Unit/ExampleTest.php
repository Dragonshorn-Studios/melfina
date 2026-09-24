<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    public function test_that_true_is_true(): void
    {
        $this->assertTrue(true);
    }

    public function test_tokens_css_contains_locked_palette(): void
    {
        $tokens = file_get_contents(dirname(__DIR__, 2).'/resources/css/tokens.css');

        $this->assertNotFalse($tokens);
        $this->assertStringContainsString('--color-void: #0B1220;', $tokens);
        $this->assertStringContainsString('--color-panel: #152238;', $tokens);
        $this->assertStringContainsString('--color-amber: #E8A838;', $tokens);
        $this->assertStringContainsString('--color-amber-dim: #B8842A;', $tokens);
        $this->assertStringContainsString('--color-cream: #E8E4D8;', $tokens);
        $this->assertStringContainsString('--color-steel: #8A9BB0;', $tokens);
        $this->assertStringContainsString('--color-danger: #C45C4A;', $tokens);
        $this->assertStringContainsString('--color-border: #2A3A52;', $tokens);
        $this->assertStringContainsString('--font-ui: \'Manrope\'', $tokens);
        $this->assertStringContainsString('--font-mono: \'IBM Plex Mono\'', $tokens);
    }
}
