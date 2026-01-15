<?php
namespace ACG\Certificados\Services;

use Exception;
use Fpdf\Fpdf;

/**
 * Servicio de Generación de PDFs de Certificados
 *
 * MVP: Plantilla hardcodeada
 * TODO: En futuro, cargar plantilla desde Google Drive
 */
class PdfService
{
    private string $storagePath;
    private string $fontsPath;

    public function __construct()
    {
        $this->storagePath = PDF_STORAGE_PATH;
        $this->fontsPath = BASE_PATH . '/lib/fonts';

        // Crear directorio de almacenamiento si no existe
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0775, true);
        }
    }

    /**
     * Genera un certificado en formato PDF
     *
     * @param array $certificateData Datos del certificado
     * @return string Path del PDF generado
     * @throws Exception Si hay error en generación
     */
    public function generateCertificate(array $certificateData): string
    {
        try {
            // Validar datos requeridos
            $this->validateCertificateData($certificateData);

            // Crear PDF
            $pdf = new Fpdf('L', 'mm', 'Letter'); // Landscape, milímetros, carta
            $pdf->SetMargins(20, 20, 20);
            $pdf->AddPage();

            // Generar contenido del certificado
            $this->renderCertificate($pdf, $certificateData);

            // Guardar PDF
            $filename = $this->generateFilename($certificateData);
            $filepath = $this->storagePath . '/' . $filename;

            $pdf->Output('F', $filepath);

            // Log de generación
            $this->logGeneration($certificateData, $filename);

            return $filepath;

        } catch (Exception $e) {
            error_log("Error generando certificado: " . $e->getMessage());
            throw new Exception("Error generando PDF: " . $e->getMessage());
        }
    }

    /**
     * Valida que los datos del certificado sean correctos
     */
    private function validateCertificateData(array $data): void
    {
        $required = [
            'numero_certificado',
            'firstname',
            'lastname',
            'course_name',
            'intensidad',
            'fecha_emision'
        ];

        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new Exception("Campo requerido faltante: {$field}");
            }
        }
    }

    /**
     * Convierte texto UTF-8 a ISO-8859-1 para FPDF
     */
    private function utf8ToLatin1(string $text): string
    {
        return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
    }

    /**
     * Renderiza el contenido del certificado en el PDF
     */
    private function renderCertificate(Fpdf $pdf, array $data): void
    {
        // =======================================================================
        // ENCABEZADO - Logo y título
        // =======================================================================

        // Logo ACG (si existe)
        $logoPath = BASE_PATH . '/public/assets/logo-acg.png';
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 20, 20, 40);
        }

        $pdf->SetY(30);
        $pdf->SetFont('Arial', 'B', 24);
        $pdf->SetTextColor(41, 128, 185); // Azul ACG
        $pdf->Cell(0, 10, $this->utf8ToLatin1('CERTIFICADO DE ASISTENCIA'), 0, 1, 'C');

        $pdf->Ln(5);
        $pdf->SetFont('Arial', 'I', 12);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 8, $this->utf8ToLatin1('Grupo Capacitación ACG Calidad'), 0, 1, 'C');

        // =======================================================================
        // NÚMERO DE CERTIFICADO
        // =======================================================================
        $pdf->Ln(10);
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 6, $this->utf8ToLatin1('Certificado N° ' . $data['numero_certificado']), 0, 1, 'C');

        // =======================================================================
        // CUERPO - Texto principal
        // =======================================================================
        $pdf->Ln(15);
        $pdf->SetFont('Arial', '', 12);
        $pdf->SetTextColor(50, 50, 50);

        // "Se certifica que"
        $pdf->Cell(0, 8, $this->utf8ToLatin1('La Asociación Colombiana de Gestión Humana certifica que'), 0, 1, 'C');

        $pdf->Ln(5);

        // Nombre del participante
        $fullName = strtoupper($data['firstname'] . ' ' . $data['lastname']);
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->SetTextColor(41, 128, 185);
        $pdf->Cell(0, 10, $this->utf8ToLatin1($fullName), 0, 1, 'C');

        $pdf->Ln(3);

        // Identificación (si existe)
        if (isset($data['idnumber']) && !empty($data['idnumber'])) {
            $pdf->SetFont('Arial', '', 11);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->Cell(0, 6, 'C.C. ' . $data['idnumber'], 0, 1, 'C');
        }

        $pdf->Ln(8);

        // Texto "asistió y participó"
        $pdf->SetFont('Arial', '', 12);
        $pdf->SetTextColor(50, 50, 50);
        $pdf->Cell(0, 8, $this->utf8ToLatin1('asistió y participó en el curso'), 0, 1, 'C');

        $pdf->Ln(5);

        // Nombre del curso
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->MultiCell(0, 8, $this->utf8ToLatin1(strtoupper($data['course_name'])), 0, 'C');

        $pdf->Ln(5);

        // Intensidad horaria
        $pdf->SetFont('Arial', '', 11);
        $pdf->SetTextColor(50, 50, 50);
        $intensidad = $data['intensidad'];
        $pdf->Cell(0, 6, $this->utf8ToLatin1("con una intensidad de {$intensidad} horas"), 0, 1, 'C');

        // Calificación (si existe y es >= 80)
        if (isset($data['calificacion']) && $data['calificacion'] >= 80) {
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetTextColor(39, 174, 96); // Verde
            $pdf->Cell(0, 6, $this->utf8ToLatin1("Calificación: {$data['calificacion']}/100"), 0, 1, 'C');
        }

        // =======================================================================
        // PIE - Fecha y firma
        // =======================================================================
        $pdf->Ln(15);

        // Fecha de emisión
        $pdf->SetFont('Arial', 'I', 10);
        $pdf->SetTextColor(100, 100, 100);
        $fechaFormateada = date('d \d\e F \d\e Y', $data['fecha_emision']);
        $fechaFormateada = $this->translateMonth($fechaFormateada);
        $pdf->Cell(0, 6, $this->utf8ToLatin1("Bogotá D.C., {$fechaFormateada}"), 0, 1, 'C');

        $pdf->Ln(20);

        // Línea de firma
        $pdf->SetDrawColor(100, 100, 100);
        $pdf->Line(80, $pdf->GetY(), 160, $pdf->GetY());

        $pdf->Ln(2);

        // Nombre del firmante
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 5, $this->utf8ToLatin1('GRUPO CAPACITACIÓN ACG'), 0, 1, 'C');
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 5, $this->utf8ToLatin1('Asociación Colombiana de Gestión Humana'), 0, 1, 'C');

        // =======================================================================
        // CÓDIGO DE VALIDACIÓN
        // =======================================================================
        $pdf->SetY(-25);
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(150, 150, 150);

        if (isset($data['hash_validacion'])) {
            $hash = substr($data['hash_validacion'], 0, 16);
            $pdf->Cell(0, 4, $this->utf8ToLatin1("Código de validación: {$hash}"), 0, 1, 'C');
        }

        $pdf->Cell(0, 4, $this->utf8ToLatin1("Valide este certificado en: https://aulavirtual.acgcalidad.co/certificados/validar"), 0, 1, 'C');
    }

    /**
     * Traduce nombres de meses al español
     */
    private function translateMonth(string $fecha): string
    {
        $meses = [
            'January' => 'enero',
            'February' => 'febrero',
            'March' => 'marzo',
            'April' => 'abril',
            'May' => 'mayo',
            'June' => 'junio',
            'July' => 'julio',
            'August' => 'agosto',
            'September' => 'septiembre',
            'October' => 'octubre',
            'November' => 'noviembre',
            'December' => 'diciembre'
        ];

        return str_replace(array_keys($meses), array_values($meses), $fecha);
    }

    /**
     * Genera nombre de archivo para el PDF
     */
    private function generateFilename(array $data): string
    {
        $numero = preg_replace('/[^A-Za-z0-9]/', '_', $data['numero_certificado']);
        $timestamp = date('YmdHis');
        return "certificado_{$numero}_{$timestamp}.pdf";
    }

    /**
     * Registra la generación del certificado en el log
     */
    private function logGeneration(array $data, string $filename): void
    {
        $logFile = LOG_PATH . '/pdf-generation.log';
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $logEntry = sprintf(
            "[%s] Generated certificate %s for user %s %s - File: %s\n",
            date('Y-m-d H:i:s'),
            $data['numero_certificado'],
            $data['firstname'],
            $data['lastname'],
            $filename
        );

        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }

    /**
     * Obtiene el path de un certificado existente
     *
     * @param string $numeroCertificado Número de certificado
     * @return string|null Path del PDF o null si no existe
     */
    public function getCertificatePath(string $numeroCertificado): ?string
    {
        // Buscar archivo por patrón
        $pattern = $this->storagePath . "/certificado_" . preg_replace('/[^A-Za-z0-9]/', '_', $numeroCertificado) . "_*.pdf";
        $files = glob($pattern);

        if (!empty($files)) {
            // Retornar el más reciente
            usort($files, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            return $files[0];
        }

        return null;
    }

    /**
     * Elimina PDFs antiguos (limpieza)
     *
     * @param int $daysOld Eliminar PDFs más antiguos que X días
     * @return int Cantidad de archivos eliminados
     */
    public function cleanOldCertificates(int $daysOld = 90): int
    {
        $files = glob($this->storagePath . "/certificado_*.pdf");
        $count = 0;
        $threshold = time() - ($daysOld * 86400);

        foreach ($files as $file) {
            if (filemtime($file) < $threshold) {
                if (unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
