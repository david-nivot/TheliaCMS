<?php

// Writes the test picture of the framing bench: 1600 x 1000 px, four plain
// quarters (top left red, top right green, bottom left blue, bottom right
// yellow), so the colour at a corner of the file served says which part of the
// picture was kept. Usage: php make-quadrants.php quadrants.png, then upload it
// to the media library (see README.md).

$image = imagecreatetruecolor(1600, 1000);
$colours = [
    [0, 0, 230, 40, 40],
    [800, 0, 40, 170, 60],
    [0, 500, 40, 80, 230],
    [800, 500, 240, 210, 40],
];

foreach ($colours as [$x, $y, $red, $green, $blue]) {
    imagefilledrectangle($image, $x, $y, $x + 799, $y + 499, imagecolorallocate($image, $red, $green, $blue));
}

imagepng($image, $argv[1] ?? 'quadrants.png');
