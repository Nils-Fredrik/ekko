<?php

test('the shell theme is applied before the page is painted', function () {
    $html = $this->get('/login')->assertOk()->getContent();

    expect($html)
        ->toContain('data-theme="dark"')
        ->toContain("localStorage.getItem('ekko.theme')")
        ->not->toContain('fonts.bunny.net');

    $script = strpos($html, "localStorage.getItem('ekko.theme')");
    $body = strpos($html, '<body');

    expect($script)->toBeInt()->toBeLessThan($body);
});

test('the theme tokens and Suisse Intl files match the Varde shell', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('--color-primary: #e84a38')
        ->toContain('--color-primary-deep: #c73823')
        ->toContain('--color-secondary: #791220')
        ->toContain('--color-accent: #ffb6c3')
        ->toContain('--color-ink: #1a1212')
        ->toContain('--color-light-beige: #f5ede4')
        ->toContain('--color-shell: #000000')
        ->toContain('--color-shell: #f5ede4')
        ->toContain('[data-theme=\'light\']')
        ->toContain("font-family: 'Suisse Intl'");

    foreach (['Light', 'Book', 'Regular', 'Medium'] as $weight) {
        expect(is_readable(public_path("fonts/SuisseIntl-{$weight}.woff2")))->toBeTrue();
    }
});
