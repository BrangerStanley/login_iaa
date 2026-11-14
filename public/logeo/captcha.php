<?php
// public/logeo/captcha.php
// Generador de captcha por imagen (GD).
require_once __DIR__ . '/../../config/seguridad.php';
configurarSesionSegura();

$alfabeto = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
$longitud = 5;
$codigo   = '';
for ($i = 0; $i < $longitud; $i++) {
    $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
}

$_SESSION['_captcha_hash'] = hash('sha256', $codigo);

$fuente = 'C:/Windows/Fonts/arialbd.ttf';
$ancho  = 170;
$alto   = 60;

$imagen = imagecreatetruecolor($ancho, $alto);
$fondo  = imagecolorallocate($imagen, 245, 245, 245);
imagefill($imagen, 0, 0, $fondo);

for ($i = 0; $i < 5; $i++) {
    $colorLinea = imagecolorallocate($imagen, random_int(120, 200), random_int(120, 200), random_int(120, 200));
    imageline($imagen, random_int(0, $ancho), random_int(0, $alto), random_int(0, $ancho), random_int(0, $alto), $colorLinea);
}

for ($i = 0; $i < 150; $i++) {
    $colorPunto = imagecolorallocate($imagen, random_int(0, 220), random_int(0, 220), random_int(0, 220));
    imagesetpixel($imagen, random_int(0, $ancho), random_int(0, $alto), $colorPunto);
}

if (file_exists($fuente) && function_exists('imagettftext')) {
    $paso = $ancho / ($longitud + 1);
    for ($i = 0; $i < $longitud; $i++) {
        $tamano  = random_int(22, 28);
        $angulo  = random_int(-25, 25);
        $color   = imagecolorallocate($imagen, random_int(0, 90), random_int(0, 90), random_int(0, 90));
        $x       = ($i + 0.5) * $paso;
        $y       = random_int(42, 52);
        imagettftext($imagen, $tamano, $angulo, (int)$x, (int)$y, $color, $fuente, $codigo[$i]);
    }
} else {
    $paso = $ancho / ($longitud + 1);
    for ($i = 0; $i < $longitud; $i++) {
        $color = imagecolorallocate($imagen, random_int(0, 90), random_int(0, 90), random_int(0, 90));
        imagestring($imagen, 5, (int)(($i + 0.6) * $paso), random_int(16, 28), $codigo[$i], $color);
    }
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Content-Type: image/png');
imagepng($imagen);
imagedestroy($imagen);
