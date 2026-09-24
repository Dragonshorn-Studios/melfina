<?php

test('that true is true', function () {
    expect(true)->toBeTrue();
});

test('tokens css contains the locked palette', function () {
    $tokens = file_get_contents(dirname(__DIR__, 2).'/resources/css/tokens.css');

    expect($tokens)->not->toBeFalse();
    expect($tokens)
        ->toContain('--color-void: #0B1220;')
        ->toContain('--color-panel: #152238;')
        ->toContain('--color-amber: #E8A838;')
        ->toContain('--color-amber-dim: #B8842A;')
        ->toContain('--color-cream: #E8E4D8;')
        ->toContain('--color-steel: #8A9BB0;')
        ->toContain('--color-danger: #C45C4A;')
        ->toContain('--color-border: #2A3A52;')
        ->toContain("--font-ui: 'Manrope'")
        ->toContain("--font-mono: 'IBM Plex Mono'");
});
