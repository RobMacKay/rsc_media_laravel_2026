<?php

use App\Models\StudioSetting;

test('the manifest is served as a manifest, not as a file the browser has to guess at', function () {
    $this->get(route('manifest'))
        ->assertOk()
        ->assertHeader('content-type', 'application/manifest+json');
});

test('it carries what a device needs to install the portal', function () {
    $manifest = $this->get(route('manifest'))->json();

    expect($manifest)
        ->toHaveKeys(['id', 'name', 'short_name', 'start_url', 'scope', 'display', 'icons'])
        ->and($manifest['display'])->toBe('standalone')
        // The portal is the app; the marketing site is not.
        ->and($manifest['start_url'])->toBe('/client')
        ->and($manifest['background_color'])->toBe('#04121a');
});

test('it names the studio from settings rather than repeating it', function () {
    StudioSetting::current()->update(['company_name' => 'RSC Media Ltd']);

    $manifest = $this->get(route('manifest'))->json();

    expect($manifest['name'])->toBe('RSC Media Ltd client area')
        ->and($manifest['short_name'])->toBe('RSC Media Ltd');
});

test('every icon it advertises actually exists at the size it claims', function () {
    $icons = $this->get(route('manifest'))->json('icons');

    expect($icons)->not->toBeEmpty();

    foreach ($icons as $icon) {
        $path = public_path(ltrim($icon['src'], '/'));

        expect(file_exists($path))->toBeTrue("missing: {$icon['src']}");

        if ($icon['type'] === 'image/png') {
            [$width, $height] = getimagesize($path);

            expect("{$width}x{$height}")->toBe($icon['sizes'], "wrong size: {$icon['src']}");
        }
    }
});

test('it offers both a plain and a maskable icon, because platforms crop differently', function () {
    $icons = collect($this->get(route('manifest'))->json('icons'));

    expect($icons->where('purpose', 'maskable')->pluck('sizes')->all())->toContain('192x192', '512x512')
        ->and($icons->where('purpose', 'any')->pluck('sizes')->all())->toContain('192x192', '512x512');
});

test('every page points a device at the manifest', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('rel="manifest"', false)
        // The address bar follows the theme the person is actually using.
        ->assertSee('media="(prefers-color-scheme: dark)"', false);
});
