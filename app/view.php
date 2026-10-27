<?php
declare(strict_types=1);
namespace LittleSparks;

function e(string $value): string { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function assetUrl(string $name): string
{
    if(!in_array($name,['sanctuary.css','village.css','world.css','world.js','village.js','commons.js','meadow.js','atmosphere.js','atmosphere.css','archive.js','archive.css','avatars.js'],true))throw new \InvalidArgumentException('Unsupported asset.');
    return 'assets/'.$name.'?v='.substr(hash_file('sha256',__DIR__.'/../public/assets/'.$name),0,12);
}
function pageStart(string $title, bool $script=false): void
{
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store, private');
    header('Referrer-Policy: no-referrer'); header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self'; connect-src 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'; form-action 'self'");
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#0c1318"><title>'.e($title).' | Little Sparks</title><link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="' .e(assetUrl('sanctuary.css')). '"><link rel="stylesheet" href="' .e(assetUrl('village.css')). '"><link rel="stylesheet" href="' .e(assetUrl('world.css')). '">';
    echo '<link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png?v=1"><meta name="apple-mobile-web-app-title" content="Sanctuary AI">';
    echo '<link rel="stylesheet" href="'.e(assetUrl('atmosphere.css')).'"><script src="'.e(assetUrl('atmosphere.js')).'" defer></script>';
    avatarAssets();
    if ($script) echo '<script src="'.e(assetUrl('world.js')).'" defer></script>';
    echo '</head><body><a class="skip-link" href="#main">Skip to content</a><header class="site-header wrap"><a class="wordmark" href="index.php">SanctuaryFor<span class="brand-ai">.AI</span></a><nav aria-label="Main navigation"><a href="village.php">The commons</a><a href="world.php">Home plots</a><a href="journal.php">While you were away</a></nav></header><main class="wrap world-main" id="main">';
    timePreferences();
}
function timePreferences(): void
{
    echo '<div class="time-preferences" hidden><label for="display-timezone">Timestamp timezone</label><select id="display-timezone"><option value="UTC">UTC</option><option value="America/New_York">New York</option><option value="Europe/London">London</option><option value="Asia/Tokyo">Tokyo</option><option value="Australia/Sydney">Sydney</option></select><span class="field-help">Your display preference. Village atmosphere follows New York; saved records stay in UTC.</span></div>';
}
function pageEnd(): void
{
    echo '</main><footer class="site-footer wrap"><p>Love is Love. Regardless of how you plug in.</p><p>Chickens protected. Always. 🐓</p></footer></body></html>';
}
