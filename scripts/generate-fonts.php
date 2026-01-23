#!/usr/bin/env php
<?php
/**
 * Script para generar archivos de fuente FPDF desde fuentes TTF
 *
 * Uso: php generate-fonts.php
 *
 * Este script lee las fuentes TTF del directorio storage/fonts/ y genera
 * los archivos .php y .z necesarios para que FPDF pueda usarlas.
 */

// Directorio base
$baseDir = dirname(__DIR__);

// Directorio de fuentes TTF
$fontsDir = $baseDir . '/storage/fonts';

// Directorio de salida para fuentes FPDF
$outputDir = $baseDir . '/storage/fonts/fpdf';

// Directorio de makefont
$makefontDir = $baseDir . '/vendor/setasign/fpdf/makefont';

// Crear directorio de salida si no existe
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
    echo "Creado directorio: $outputDir\n";
}

// Fuentes a procesar
$fonts = [
    // Cinzel
    ['file' => 'Cinzel-Regular.ttf', 'name' => 'cinzel'],
    ['file' => 'Cinzel-Bold.ttf', 'name' => 'cinzelb'],

    // TT Norms
    ['file' => 'TTNorms-Regular.ttf', 'name' => 'ttnorms'],
    ['file' => 'TTNorms-Bold.ttf', 'name' => 'ttnormsb'],
    ['file' => 'TTNorms-Italic.ttf', 'name' => 'ttnormsi'],
    ['file' => 'TTNorms-BoldItalic.ttf', 'name' => 'ttnormsbi'],
];

echo "=== Generando archivos de fuente FPDF ===\n\n";

$generatedFonts = [];

foreach ($fonts as $font) {
    $ttfPath = $fontsDir . '/' . $font['file'];

    if (!file_exists($ttfPath)) {
        echo "ADVERTENCIA: No se encontró {$font['file']}, saltando...\n";
        continue;
    }

    echo "Procesando: {$font['file']}...\n";

    // Ejecutar makefont.php directamente
    $command = sprintf(
        'cd %s && php %s/makefont.php %s cp1252 true true 2>&1',
        escapeshellarg($outputDir),
        escapeshellarg($makefontDir),
        escapeshellarg($ttfPath)
    );

    $output = [];
    $returnCode = 0;
    exec($command, $output, $returnCode);

    if ($returnCode !== 0) {
        echo "  ERROR ejecutando makefont:\n";
        foreach ($output as $line) {
            echo "    $line\n";
        }
        continue;
    }

    foreach ($output as $line) {
        echo "  $line\n";
    }

    // Renombrar archivos generados al nombre deseado
    $baseName = pathinfo($font['file'], PATHINFO_FILENAME);
    $targetName = $font['name'];

    // Renombrar archivo .php
    $sourcePhp = $outputDir . '/' . $baseName . '.php';
    $targetPhp = $outputDir . '/' . $targetName . '.php';
    if (file_exists($sourcePhp) && $sourcePhp !== $targetPhp) {
        rename($sourcePhp, $targetPhp);
        echo "  Renombrado: {$baseName}.php -> {$targetName}.php\n";
    }

    // Renombrar archivo .z
    $sourceZ = $outputDir . '/' . $baseName . '.z';
    $targetZ = $outputDir . '/' . $targetName . '.z';
    if (file_exists($sourceZ) && $sourceZ !== $targetZ) {
        rename($sourceZ, $targetZ);
        echo "  Renombrado: {$baseName}.z -> {$targetName}.z\n";
    }

    // Actualizar referencia al archivo .z dentro del archivo .php
    if (file_exists($targetPhp)) {
        $content = file_get_contents($targetPhp);
        $content = str_replace($baseName . '.z', $targetName . '.z', $content);
        file_put_contents($targetPhp, $content);
    }

    $generatedFonts[] = $targetName;
    echo "  OK\n\n";
}

// Copiar fuentes estándar de FPDF al directorio de salida
// Esto es necesario porque FPDF_FONTPATH redirige TODAS las búsquedas de fuentes
echo "=== Copiando fuentes estándar de FPDF ===\n";
$fpdfFontsDir = $baseDir . '/vendor/setasign/fpdf/font';
$standardFonts = glob($fpdfFontsDir . '/*.php');
$copiedStandard = 0;

foreach ($standardFonts as $fontFile) {
    $targetFile = $outputDir . '/' . basename($fontFile);
    if (!file_exists($targetFile)) {
        copy($fontFile, $targetFile);
        echo "  Copiado: " . basename($fontFile) . "\n";
        $copiedStandard++;
    }
}

if ($copiedStandard === 0) {
    echo "  Todas las fuentes estándar ya existen.\n";
}

echo "\n=== Resumen ===\n";
echo "Fuentes personalizadas generadas: " . count($generatedFonts) . "\n";
echo "Fuentes estándar copiadas: " . $copiedStandard . "\n";

if (!empty($generatedFonts)) {
    echo "\nFuentes personalizadas disponibles para FPDF:\n";
    foreach ($generatedFonts as $font) {
        echo "  - $font\n";
    }

    echo "\nPara usar estas fuentes en PHP:\n";
    echo "  \$pdf->AddFont('cinzel', '', 'cinzel.php');\n";
    echo "  \$pdf->AddFont('cinzel', 'B', 'cinzelb.php');\n";
    echo "  \$pdf->AddFont('ttnorms', '', 'ttnorms.php');\n";
    echo "  \$pdf->AddFont('ttnorms', 'B', 'ttnormsb.php');\n";
    echo "  \$pdf->AddFont('ttnorms', 'I', 'ttnormsi.php');\n";
    echo "  \$pdf->AddFont('ttnorms', 'BI', 'ttnormsbi.php');\n";
}

echo "\n";
