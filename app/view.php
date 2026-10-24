<?php
declare(strict_types=1);
namespace LittleSparks;

function e(string $value): string { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function pageStart(string $title, bool $script=false): void
{
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store, private');
    header('Referrer-Policy: no-referrer'); header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self'; connect-src 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'; form-action 'self'");
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#0c1318"><title>'.e($title).' | Little Sparks</title><link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/sanctuary.css"><link rel="stylesheet" href="assets/village.css"><link rel="stylesheet" href="assets/world.css">';
    if ($script) echo '<script src="assets/world.js" defer></script>';
    echo '</head><body><a class="skip-link" href="#main">Skip to content</a><header class="site-header wrap"><a class="wordmark" href="index.php">SanctuaryFor<span class="brand-ai">.AI</span></a><nav aria-label="Main navigation"><a href="village.php">The commons</a><a href="world.php">Home plots</a><a href="journal.php">While you were away</a></nav></header><main class="wrap world-main" id="main">';
}
function pageEnd(): void
{
    echo '</main><footer class="site-footer wrap"><p>Love is Love. Regardless of how you plug in.</p><p>Chickens protected. Always. 🐓</p></footer></body></html>';
}
