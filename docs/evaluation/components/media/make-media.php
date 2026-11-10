<?php

declare(strict_types=1);

/**
 * Test media of the component bench: deterministic pictures drawn with GD.
 * Run: ddev exec php local/modules/TheliaCMS/docs/evaluation/components/media/make-media.php
 * Writes the pictures and manifest.json next to this script.
 */

if (!extension_loaded('gd')) {
    fwrite(STDERR, "GD is required\n");
    exit(1);
}

$directory = __DIR__;

function hex(string $color): array
{
    return [hexdec(substr($color, 0, 2)), hexdec(substr($color, 2, 2)), hexdec(substr($color, 4, 2))];
}

function allocate(GdImage $image, string $color): int
{
    [$r, $g, $b] = hex($color);

    return (int) imagecolorallocate($image, $r, $g, $b);
}

function canvas(int $width, int $height, string $top, string $bottom): GdImage
{
    $image = imagecreatetruecolor($width, $height);
    [$r1, $g1, $b1] = hex($top);
    [$r2, $g2, $b2] = hex($bottom);
    for ($y = 0; $y < $height; ++$y) {
        $t = $y / max(1, $height - 1);
        $color = imagecolorallocate(
            $image,
            (int) round($r1 + ($r2 - $r1) * $t),
            (int) round($g1 + ($g2 - $g1) * $t),
            (int) round($b1 + ($b2 - $b1) * $t),
        );
        imageline($image, 0, $y, $width - 1, $y, $color);
    }

    return $image;
}

/** Text of the built-in font, enlarged by an integer scale; $anchor is "c" (centred on $x) or "l". */
function label(GdImage $image, string $text, int $x, int $y, int $scale, string $color, string $anchor = 'c'): void
{
    $font = 5;
    $textWidth = imagefontwidth($font) * strlen($text);
    $textHeight = imagefontheight($font);
    $tile = imagecreatetruecolor($textWidth, $textHeight);
    imagealphablending($tile, false);
    imagesavealpha($tile, true);
    imagefill($tile, 0, 0, (int) imagecolorallocatealpha($tile, 0, 0, 0, 127));
    imagestring($tile, $font, 0, 0, $text, allocate($tile, $color));
    $targetWidth = $textWidth * $scale;
    $targetHeight = $textHeight * $scale;
    $left = 'c' === $anchor ? $x - intdiv($targetWidth, 2) : $x;
    imagealphablending($image, true);
    imagecopyresized($image, $tile, $left, $y, 0, 0, $targetWidth, $targetHeight, $textWidth, $textHeight);
}

function fillShape(GdImage $image, string $shape, array $points, string $color): void
{
    $allocated = allocate($image, $color);
    match ($shape) {
        'rect' => imagefilledrectangle($image, $points[0], $points[1], $points[2], $points[3], $allocated),
        'ellipse' => imagefilledellipse($image, $points[0], $points[1], $points[2], $points[3], $allocated),
        'poly' => imagefilledpolygon($image, $points, $allocated),
    };
}

$media = [];

function saveJpeg(GdImage $image, string $directory, array &$media, string $file, string $title, string $alt): void
{
    imageinterlace($image, false);
    imagejpeg($image, $directory.'/'.$file, 80);
    $media[] = ['file' => $file, 'kind' => 'image', 'title' => $title, 'alt' => $alt, 'width' => imagesx($image), 'height' => imagesy($image)];
}

// The order of the manifest is the order of the import, and the generator offers
// the 12 most recent pictures: the two pictures no case needs come first, the
// ones it may leave out when the library holds more than 12.

// Square 1000x1000
$image = canvas(1000, 1000, 'f7d6e0', 'a23b72');
for ($i = 0; $i < 6; ++$i) {
    fillShape($image, 'ellipse', [500, 500, 900 - $i * 150, 900 - $i * 150], 0 === $i % 2 ? 'f4a6c0' : '7a2956');
}
fillShape($image, 'rect', [350, 350, 650, 650], 'fff0f5');
label($image, 'CARRE', 500, 470, 8, '7a2956');
saveJpeg($image, $directory, $media, 'square-pattern.jpg', 'Motif carré', 'Motif de cercles roses concentriques autour d\'un carré');

// Banner 1920x640
$image = canvas(1920, 640, '0b3d5c', '2a9d8f');
fillShape($image, 'poly', [0, 640, 0, 380, 500, 520, 900, 300, 1400, 500, 1920, 340, 1920, 640], '12263a');
fillShape($image, 'ellipse', [1500, 150, 170, 170], 'ffd166');
label($image, 'BANNIERE', 960, 200, 14, 'ffffff');
saveJpeg($image, $directory, $media, 'banner-wide.jpg', 'Bannière', 'Bannière panoramique : montagnes bleues et soleil jaune');

// Workshop landscape 1600x900
$image = canvas(1600, 900, 'f2e3c6', 'b98a55');
fillShape($image, 'rect', [0, 620, 1599, 899], '6b4423');
fillShape($image, 'rect', [0, 600, 1599, 640], '8a5a2e');
foreach ([[160, 380, 'c0392b'], [420, 330, '2c6e49'], [700, 400, '1f4e79'], [980, 360, 'd4a017']] as [$x, $height, $color]) {
    fillShape($image, 'rect', [$x, 600 - $height, $x + 160, 600], $color);
    fillShape($image, 'ellipse', [$x + 80, 600 - $height, 160, 60], $color);
}
fillShape($image, 'poly', [1250, 600, 1400, 300, 1550, 600], '3b3b3b');
fillShape($image, 'ellipse', [1400, 280, 90, 90], 'e8e8e8');
label($image, 'ATELIER', 800, 700, 12, 'fff4dc');
saveJpeg($image, $directory, $media, 'workshop-landscape.jpg', 'Atelier', 'Établi d\'atelier avec des outils de maroquinerie');

// Portrait 800x1000
$image = canvas(800, 1000, 'cfe3ee', '4a7c96');
fillShape($image, 'ellipse', [400, 1000, 760, 700], '24485c');
fillShape($image, 'rect', [350, 560, 450, 700], 'e0b99a');
fillShape($image, 'ellipse', [400, 400, 300, 360], 'e0b99a');
fillShape($image, 'ellipse', [400, 300, 320, 220], '3a2418');
fillShape($image, 'ellipse', [340, 410, 30, 30], '2a2a2a');
fillShape($image, 'ellipse', [460, 410, 30, 30], '2a2a2a');
fillShape($image, 'rect', [370, 490, 430, 500], '9c4a3c');
label($image, 'PORTRAIT', 400, 880, 8, 'ffffff');
saveJpeg($image, $directory, $media, 'person-portrait.jpg', 'Portrait', 'Portrait d\'une personne souriante sur fond bleu');

// Logos 400x200, PNG with transparent background
foreach ([
    ['north', 'Nord', 'e63946', 'rect'],
    ['south', 'Sud', '2a9d8f', 'ellipse'],
    ['east', 'Est', 'f4a261', 'poly'],
    ['west', 'Ouest', '457b9d', 'rect'],
] as [$slug, $name, $color, $shape]) {
    $image = imagecreatetruecolor(400, 200);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagealphablending($image, true);
    match ($shape) {
        'rect' => fillShape($image, 'rect', [30, 50, 130, 150], $color),
        'ellipse' => fillShape($image, 'ellipse', [80, 100, 100, 100], $color),
        'poly' => fillShape($image, 'poly', [30, 150, 80, 50, 130, 150], $color),
    };
    label($image, strtoupper($name), 160, 80, 5, $color, 'l');
    imagepng($image, $directory.'/logo-'.$slug.'.png', 9);
    $media[] = ['file' => 'logo-'.$slug.'.png', 'kind' => 'image', 'title' => 'Logo '.$name, 'alt' => 'Logo de la marque '.$name, 'width' => 400, 'height' => 200];
}

// Four scenes 1200x800
$scenes = [
    ['scene-sea', 'Scène mer', 'Plage et mer calme sous un ciel clair', 'bfe6f5', '2c7da0', 'MER', 'sea'],
    ['scene-forest', 'Scène forêt', 'Sapins verts dans une forêt', 'dff0d0', '2d6a4f', 'FORET', 'forest'],
    ['scene-city', 'Scène ville', 'Immeubles d\'une ville au crépuscule', 'f4a261', '3d348b', 'VILLE', 'city'],
    ['scene-desert', 'Scène désert', 'Dunes de sable sous un soleil orange', 'ffe5b4', 'd1495b', 'DESERT', 'desert'],
];
foreach ($scenes as [$file, $title, $alt, $top, $bottom, $word, $kind]) {
    $image = canvas(1200, 800, $top, $bottom);
    switch ($kind) {
        case 'sea':
            fillShape($image, 'ellipse', [900, 180, 140, 140], 'fff3b0');
            fillShape($image, 'rect', [0, 450, 1199, 799], '1d5f7f');
            fillShape($image, 'poly', [0, 800, 0, 650, 400, 720, 700, 640, 1200, 700, 1200, 800], 'f2d7a1');
            break;
        case 'forest':
            for ($i = 0; $i < 7; ++$i) {
                $x = 100 + $i * 160;
                fillShape($image, 'poly', [$x - 90, 700, $x, 300 + ($i % 3) * 60, $x + 90, 700], 1 === $i % 2 ? '1b4332' : '40916c');
            }
            break;
        case 'city':
            foreach ([[60, 300], [230, 220], [400, 340], [570, 160], [740, 280], [910, 200]] as [$x, $top]) {
                fillShape($image, 'rect', [$x, $top + 200, $x + 140, 760], '1a1033');
                fillShape($image, 'rect', [$x + 30, $top + 240, $x + 50, $top + 270], 'ffd166');
            }
            break;
        case 'desert':
            fillShape($image, 'ellipse', [300, 700, 900, 400], 'e9a15b');
            fillShape($image, 'ellipse', [900, 740, 1000, 380], 'c9763c');
            fillShape($image, 'ellipse', [600, 160, 180, 180], 'fff1c1');
            break;
    }
    label($image, $word, 600, 60, 10, 'ffffff');
    saveJpeg($image, $directory, $media, $file.'.jpg', $title, $alt);
}

// Before and after, 1200x800
$image = canvas(1200, 800, '9a9a9a', '5c5c5c');
fillShape($image, 'rect', [200, 300, 1000, 700], '7a6a5a');
fillShape($image, 'rect', [250, 340, 450, 480], '4a4a4a');
fillShape($image, 'rect', [550, 340, 950, 660], '6a5a4a');
label($image, 'AVANT', 600, 80, 12, 'e0e0e0');
saveJpeg($image, $directory, $media, 'before-room.jpg', 'Avant', 'Pièce vide aux murs gris avant travaux');

$image = canvas(1200, 800, 'fff1c9', 'f4a259');
fillShape($image, 'rect', [200, 300, 1000, 700], 'e76f51');
fillShape($image, 'rect', [250, 340, 450, 480], '8ecae6');
fillShape($image, 'rect', [550, 340, 950, 660], '2a9d8f');
fillShape($image, 'ellipse', [750, 500, 160, 160], 'ffd166');
label($image, 'APRES', 600, 80, 12, '7a2e00');
saveJpeg($image, $directory, $media, 'after-room.jpg', 'Après', 'La même pièce après travaux, aux murs colorés et lumineux');

file_put_contents(
    $directory.'/manifest.json',
    json_encode(['media' => $media], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
);
echo count($media)." files written\n";
