<?php
// FlyRes-PWA-Icons erzeugen (GD): weisser Papierflieger auf blauem Verlauf.
// Aufruf:  php frontend/scripts/make-icons.php frontend/public/icons
// danach:  npm run build   (kopiert die Icons nach public/app/icons/)

$SS = 1024; // Supersampling fuer glatte Kanten

function lerp($a, $b, $t) { return (int) round($a + ($b - $a) * $t); }

function render(int $SS, float $contentScale) {
    $im = imagecreatetruecolor($SS, $SS);
    imagealphablending($im, true);

    // Vertikaler Verlauf: #0A84FF -> #005ACC (Theme-Farbe)
    $top = [0x0a, 0x84, 0xff];
    $bot = [0x00, 0x5a, 0xcc];
    for ($y = 0; $y < $SS; $y++) {
        $t = $y / ($SS - 1);
        $c = imagecolorallocate($im, lerp($top[0], $bot[0], $t), lerp($top[1], $bot[1], $t), lerp($top[2], $bot[2], $t));
        imageline($im, 0, $y, $SS - 1, $y, $c);
    }

    // Papierflieger (lucide "send") im 24er-Raster
    $pts24 = [[22, 2], [15, 22], [11, 13], [2, 11]];
    $C   = $SS * $contentScale;
    $off = ($SS - $C) / 2;
    $sc  = $C / 24;

    $flat = [];
    foreach ($pts24 as $p) { $flat[] = (int) ($off + $p[0] * $sc); $flat[] = (int) ($off + $p[1] * $sc); }

    imagefilledpolygon($im, $flat, imagecolorallocate($im, 255, 255, 255));

    // Falzlinie (Nase -> innerer Knick)
    $crease = imagecolorallocatealpha($im, 0x0a, 0x4a, 0x90, 70);
    imagesetthickness($im, (int) max(2, $sc * 0.5));
    imageline($im, (int) ($off + 22 * $sc), (int) ($off + 2 * $sc), (int) ($off + 11 * $sc), (int) ($off + 13 * $sc), $crease);

    return $im;
}

function save($im, int $SS, int $size, string $path) {
    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $SS, $SS);
    imagepng($out, $path);
    imagedestroy($out);
    echo "  $path ({$size}x{$size})\n";
}

$dir = $argv[1] ?? __DIR__ . '/../public/icons';
if (!is_dir($dir)) { mkdir($dir, 0775, true); }

$normal = render($SS, 0.60);
save($normal, $SS, 512, "$dir/icon-512.png");
save($normal, $SS, 192, "$dir/icon-192.png");
imagedestroy($normal);

$mask = render($SS, 0.44); // mehr Sicherheitsrand fuer "maskable"
save($mask, $SS, 512, "$dir/icon-512-maskable.png");
imagedestroy($mask);

echo "Fertig.\n";
