<?php
/**
 * Динамическая генерация иконок PWA
 * Использование: icon.php?size=192
 */

$size = max(16, min(512, (int)($_GET['size'] ?? 192)));

header('Content-Type: image/svg+xml');
header('Cache-Control: public, max-age=31536000');

echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$size}" height="{$size}" viewBox="0 0 192 192">
    <defs>
        <linearGradient id="bg" x1="0" y1="0" x2="192" y2="192" gradientUnits="userSpaceOnUse">
            <stop offset="0%" stop-color="#0f172a"/>
            <stop offset="100%" stop-color="#1e293b"/>
        </linearGradient>
        <radialGradient id="glow" cx="96" cy="96" r="60" gradientUnits="userSpaceOnUse">
            <stop offset="0%" stop-color="#6366f1" stop-opacity="0.4"/>
            <stop offset="100%" stop-color="#6366f1" stop-opacity="0"/>
        </radialGradient>
    </defs>
    <rect width="192" height="192" rx="38" fill="url(#bg)"/>
    <circle cx="96" cy="96" r="60" fill="url(#glow)"/>
    <rect x="82" y="48" width="28" height="96" rx="6" fill="white"/>
    <rect x="48" y="82" width="96" height="28" rx="6" fill="white"/>
</svg>
SVG;
