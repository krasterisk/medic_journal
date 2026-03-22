<?php
/**
 * Генератор SVG-иконок для PWA
 * Запустить один раз: php generate_icons.php
 */

$sizes = [72, 96, 128, 144, 152, 192, 384, 512];
$iconsDir = __DIR__ . '/icons';

if (!is_dir($iconsDir)) {
    mkdir($iconsDir, 0755, true);
}

foreach ($sizes as $size) {
    $svg = generateSvgIcon($size);
    $filename = "{$iconsDir}/icon-{$size}.svg";
    // Save as SVG - can be served directly
    // For PNG, we need the source image or ImageMagick
    $filename = "{$iconsDir}/icon-{$size}.png";
    
    // If GD is available, generate PNG from basic drawing
    if (function_exists('imagecreatetruecolor')) {
        $img = imagecreatetruecolor($size, $size);
        imagesavealpha($img, true);
        
        // Background color #0f172a
        $bgColor = imagecolorallocate($img, 15, 23, 42);
        imagefill($img, 0, 0, $bgColor);
        
        // Draw rounded rectangle (simulated by filling)
        $radius = (int)($size * 0.2);
        
        // Cross dimensions
        $crossWidth = (int)($size * 0.15);
        $crossLength = (int)($size * 0.5);
        $cx = (int)($size / 2);
        $cy = (int)($size / 2);
        
        // Glow effect (gradient circle behind cross)
        for ($r = (int)($size * 0.35); $r > 0; $r--) {
            $alpha = (int)(127 - (127 * 0.3 * ($r / ($size * 0.35))));
            $glowColor = imagecolorallocatealpha($img, 99, 102, 241, $alpha);
            imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $glowColor);
        }
        
        // White cross
        $white = imagecolorallocate($img, 255, 255, 255);
        
        // Vertical bar
        imagefilledrectangle($img, 
            $cx - (int)($crossWidth / 2),
            $cy - (int)($crossLength / 2),
            $cx + (int)($crossWidth / 2),
            $cy + (int)($crossLength / 2),
            $white
        );
        
        // Horizontal bar
        imagefilledrectangle($img,
            $cx - (int)($crossLength / 2),
            $cy - (int)($crossWidth / 2),
            $cx + (int)($crossLength / 2),
            $cy + (int)($crossWidth / 2),
            $white
        );
        
        imagepng($img, $filename);
        imagedestroy($img);
        echo "Generated: icon-{$size}.png\n";
    } else {
        echo "GD library not available. Please install php-gd.\n";
        // Fallback: create SVG files
        $svgContent = generateSvgIcon($size);
        file_put_contents("{$iconsDir}/icon-{$size}.svg", $svgContent);
        echo "Generated SVG fallback: icon-{$size}.svg\n";
    }
}

echo "\nDone! Icons generated in {$iconsDir}\n";

function generateSvgIcon(int $size): string {
    $r = $size * 0.2;
    $hw = $size * 0.075;
    $hl = $size * 0.25;
    $cx = $size / 2;
    $cy = $size / 2;
    
    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$size}" height="{$size}" viewBox="0 0 {$size} {$size}">
    <defs>
        <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stop-color="#0f172a"/>
            <stop offset="100%" stop-color="#1e293b"/>
        </linearGradient>
        <radialGradient id="glow" cx="50%" cy="50%" r="40%">
            <stop offset="0%" stop-color="#6366f1" stop-opacity="0.5"/>
            <stop offset="100%" stop-color="#6366f1" stop-opacity="0"/>
        </radialGradient>
    </defs>
    <rect width="{$size}" height="{$size}" rx="{$r}" fill="url(#bg)"/>
    <circle cx="{$cx}" cy="{$cy}" r="{$hl}" fill="url(#glow)"/>
    <rect x="{}" y="{}" width="{}" height="{}" rx="{}" fill="white"/>
    <rect x="{}" y="{}" width="{}" height="{}" rx="{}" fill="white"/>
</svg>
SVG;
}
