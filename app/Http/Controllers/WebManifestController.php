<?php

namespace App\Http\Controllers;

use App\Models\StudioSetting;
use Illuminate\Http\JsonResponse;

/**
 * The web app manifest, which is what makes the portal installable.
 *
 * Served from here rather than as a file in `public/` on purpose: a manifest
 * has to arrive as `application/manifest+json`, and whether a web server knows
 * that extension varies. Going through the application settles it.
 */
class WebManifestController extends Controller
{
    /**
     * The colour behind the splash screen and in the task switcher.
     */
    private const BRAND_INK = '#04121a';

    public function __invoke(StudioSetting $settings): JsonResponse
    {
        $name = $settings->company_name ?: config('app.name');

        return response()->json([
            'id' => '/client',
            'name' => __(':studio client area', ['studio' => $name]),
            // Home screens truncate at about a dozen characters.
            'short_name' => $name,
            'description' => __('Project progress, open tickets, invoices and the health of your sites.'),
            // The portal is the part worth installing; the marketing site is not
            // an app. Anyone not signed in lands on the sign-in screen.
            'start_url' => '/client',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => self::BRAND_INK,
            'theme_color' => self::BRAND_INK,
            'lang' => 'en-GB',
            'dir' => 'ltr',
            'categories' => ['business', 'productivity'],
            'icons' => [
                ['src' => '/icon-any-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icon-any-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                // Cropped by the platform to its own shape, so these bleed to
                // the edges and keep the mark inside the safe circle.
                ['src' => '/icon-maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => '/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => '/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
            ],
        ], headers: [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=86400',
        ], options: JSON_UNESCAPED_SLASHES);
    }
}
