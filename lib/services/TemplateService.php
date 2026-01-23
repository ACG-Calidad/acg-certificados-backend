<?php

namespace ACG\Certificados\Services;

use Exception;
use PDO;
use setasign\Fpdi\Fpdi;

/**
 * Servicio de Gestión de Plantillas de Certificados
 *
 * Maneja la subida de plantillas PDF y la generación de certificados
 * combinando plantillas base y de curso con campos dinámicos.
 *
 * Flujo:
 * 1. Gestor edita presentaciones en Google Drive
 * 2. Gestor exporta como PDF y sube a la aplicación
 * 3. Gestor define coordenadas de campos variables
 * 4. Sistema genera certificados insertando texto en coordenadas definidas
 */
class TemplateService
{
    private string $templatesPath;
    private string $tempPath;
    private string $pdfsPath;
    private PDO $pdo;

    // Tamaño máximo: 10 MB
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    // MIME types permitidos para PDF
    private const ALLOWED_MIME_TYPES = [
        'application/pdf'
    ];

    // Campos disponibles para plantilla base (página 1)
    public const BASE_TEMPLATE_FIELDS = [
        'participante' => 'Nombre completo del participante',
        'documento' => 'Número de documento de identidad',
        'curso' => 'Nombre del curso',
        'intensidad' => 'Intensidad horaria (ej: 40 horas)',
        'fecha' => 'Fecha de emisión (ej: Enero de 2026)',
        'certificado_id' => 'ID del certificado (ej: CV-3490)'
    ];

    // Campos disponibles para plantilla de curso (página 2)
    // NOTA: Estos campos se almacenan asociados a la plantilla BASE,
    // ya que se aplican igual a TODAS las segundas hojas de los certificados
    public const COURSE_TEMPLATE_FIELDS = [
        'certificado_id_pagina2' => 'ID del certificado en segunda página (ej: CV-3490)'
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->templatesPath = defined('TEMPLATES_PATH') ? TEMPLATES_PATH : BASE_PATH . '/storage/templates';
        $this->tempPath = defined('TEMP_PATH') ? TEMP_PATH : BASE_PATH . '/storage/temp';
        $this->pdfsPath = defined('PDF_STORAGE_PATH') ? PDF_STORAGE_PATH : BASE_PATH . '/storage/pdfs';

        $this->ensureDirectoriesExist();
        $this->ensureTablesExist();
    }

    /**
     * Crea la estructura de directorios necesaria
     */
    private function ensureDirectoriesExist(): void
    {
        $directories = [
            $this->templatesPath,
            $this->templatesPath . '/base',
            $this->templatesPath . '/cursos',
            $this->tempPath,
            $this->pdfsPath
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }
    }

    /**
     * Asegura que existan las tablas necesarias con la estructura correcta
     */
    private function ensureTablesExist(): void
    {
        // Verificar tabla cc_plantillas
        $stmt = $this->pdo->query("SHOW TABLES LIKE 'cc_plantillas'");
        $plantillasExists = (bool)$stmt->fetch();

        if ($plantillasExists) {
            // Verificar si tiene la estructura correcta (columna 'tipo')
            $hasCorrectStructure = $this->columnExists('cc_plantillas', 'tipo');

            if (!$hasCorrectStructure) {
                // Verificar si está vacía para poder recrearla
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM cc_plantillas");
                $count = (int)$stmt->fetchColumn();

                if ($count === 0) {
                    // Tabla vacía, podemos recrearla
                    $this->pdo->exec("DROP TABLE cc_plantillas");
                    $plantillasExists = false;
                } else {
                    throw new Exception(
                        "La tabla cc_plantillas existe con estructura incorrecta y contiene datos. " .
                            "Por favor, respalda los datos y ejecuta: DROP TABLE cc_plantillas, cc_plantillas_campos;"
                    );
                }
            }
        }

        if (!$plantillasExists) {
            $this->pdo->exec("
                CREATE TABLE cc_plantillas (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    tipo ENUM('base', 'curso') NOT NULL,
                    courseid BIGINT UNSIGNED NULL,
                    nombre VARCHAR(255) NOT NULL,
                    archivo VARCHAR(255) NOT NULL,
                    archivo_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    imagen_preview VARCHAR(255) NULL,
                    version INT UNSIGNED NOT NULL DEFAULT 1,
                    activo TINYINT(1) NOT NULL DEFAULT 1,
                    uploaded_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    created_at BIGINT UNSIGNED NOT NULL,
                    updated_at BIGINT UNSIGNED NOT NULL,
                    UNIQUE INDEX idx_tipo_curso (tipo, courseid),
                    INDEX idx_activo (activo),
                    INDEX idx_courseid (courseid)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } else {
            // Migración: agregar columna imagen_preview si no existe
            if (!$this->columnExists('cc_plantillas', 'imagen_preview')) {
                $this->pdo->exec("ALTER TABLE cc_plantillas ADD COLUMN imagen_preview VARCHAR(255) NULL AFTER archivo_size");
            }
        }

        // Verificar tabla cc_plantillas_campos
        $stmt = $this->pdo->query("SHOW TABLES LIKE 'cc_plantillas_campos'");
        $camposTableExists = (bool)$stmt->fetch();

        if (!$camposTableExists) {
            $this->pdo->exec("
                CREATE TABLE cc_plantillas_campos (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    plantilla_id BIGINT UNSIGNED NOT NULL,
                    campo VARCHAR(50) NOT NULL,
                    pos_x DECIMAL(10,2) NOT NULL DEFAULT 0,
                    pos_y DECIMAL(10,2) NOT NULL DEFAULT 0,
                    font_size INT UNSIGNED NOT NULL DEFAULT 12,
                    font_family VARCHAR(50) NOT NULL DEFAULT 'arial',
                    font_style VARCHAR(20) NOT NULL DEFAULT '',
                    text_align VARCHAR(20) NOT NULL DEFAULT 'left',
                    max_width DECIMAL(10,2) NULL,
                    color_r INT UNSIGNED NOT NULL DEFAULT 0,
                    color_g INT UNSIGNED NOT NULL DEFAULT 0,
                    color_b INT UNSIGNED NOT NULL DEFAULT 0,
                    prefix VARCHAR(100) NULL,
                    created_at BIGINT UNSIGNED NOT NULL,
                    updated_at BIGINT UNSIGNED NOT NULL,
                    UNIQUE INDEX idx_plantilla_campo (plantilla_id, campo),
                    INDEX idx_plantilla (plantilla_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } else {
            // Migración: agregar columna prefix si no existe
            if (!$this->columnExists('cc_plantillas_campos', 'prefix')) {
                $this->pdo->exec("ALTER TABLE cc_plantillas_campos ADD COLUMN prefix VARCHAR(100) NULL AFTER color_b");
            }
        }
    }

    /**
     * Verifica si una columna existe en una tabla
     */
    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Obtiene el tipo de una columna de una tabla
     */
    private function getColumnType(string $table, string $column): string
    {
        $stmt = $this->pdo->prepare("
            SELECT COLUMN_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? $result['COLUMN_TYPE'] : 'BIGINT UNSIGNED';
    }

    /**
     * Obtiene todas las plantillas con información de cursos y campos
     */
    public function getAllTemplates(): array
    {
        // Obtener plantilla base
        $stmt = $this->pdo->prepare("
            SELECT
                p.*,
                u.firstname as uploaded_by_name,
                u.lastname as uploaded_by_lastname
            FROM cc_plantillas p
            LEFT JOIN mdl_user u ON p.uploaded_by = u.id
            WHERE p.tipo = 'base' AND p.activo = 1
            LIMIT 1
        ");
        $stmt->execute();
        $baseTemplate = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($baseTemplate) {
            $baseTemplate['updated_at_formatted'] = date('Y-m-d H:i:s', $baseTemplate['updated_at']);
            $baseTemplate['archivo_size_formatted'] = $this->formatFileSize($baseTemplate['archivo_size']);
            $baseTemplate['campos'] = $this->getTemplateFields($baseTemplate['id']);
            $baseTemplate['available_fields'] = self::BASE_TEMPLATE_FIELDS;
            // Añadir dimensiones del PDF para el editor visual
            $baseTemplate['pdf_dimensions'] = $this->getPdfDimensions(
                $this->templatesPath . '/base/' . $baseTemplate['archivo']
            );
        }

        // Obtener plantillas de cursos
        $stmt = $this->pdo->prepare("
            SELECT
                p.*,
                c.fullname as course_name,
                c.shortname as course_shortname,
                u.firstname as uploaded_by_name,
                u.lastname as uploaded_by_lastname
            FROM cc_plantillas p
            LEFT JOIN mdl_course c ON p.courseid = c.id
            LEFT JOIN mdl_user u ON p.uploaded_by = u.id
            WHERE p.tipo = 'curso' AND p.activo = 1
            ORDER BY c.fullname ASC
        ");
        $stmt->execute();
        $courseTemplates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($courseTemplates as &$template) {
            $template['updated_at_formatted'] = date('Y-m-d H:i:s', $template['updated_at']);
            $template['archivo_size_formatted'] = $this->formatFileSize($template['archivo_size']);
            $template['campos'] = $this->getTemplateFields($template['id']);
            $template['available_fields'] = self::COURSE_TEMPLATE_FIELDS;
            // Añadir dimensiones del PDF para el editor visual
            $template['pdf_dimensions'] = $this->getPdfDimensions(
                $this->templatesPath . '/cursos/' . $template['archivo']
            );
        }

        // Obtener cursos sin plantilla
        $stmt = $this->pdo->prepare("
            SELECT
                c.id as courseid,
                c.fullname as course_name,
                c.shortname as course_shortname
            FROM mdl_course c
            LEFT JOIN cc_plantillas p ON c.id = p.courseid AND p.tipo = 'curso' AND p.activo = 1
            WHERE c.visible = 1
            AND c.id > 1
            AND p.id IS NULL
            ORDER BY c.fullname ASC
        ");
        $stmt->execute();
        $coursesWithoutTemplate = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Obtener campos de segunda hoja (asociados a base pero para todas las segundas páginas)
        $secondPageFields = $this->getSecondPageFields();

        return [
            'base_template' => $baseTemplate,
            'course_templates' => $courseTemplates,
            'courses_without_template' => $coursesWithoutTemplate,
            'second_page_fields' => $secondPageFields
        ];
    }

    /**
     * Obtiene los campos configurados de una plantilla
     */
    public function getTemplateFields(int $plantillaId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                campo,
                pos_x,
                pos_y,
                font_size,
                font_family,
                font_style,
                text_align,
                max_width,
                color_r,
                color_g,
                color_b,
                prefix
            FROM cc_plantillas_campos
            WHERE plantilla_id = ?
        ");
        $stmt->execute([$plantillaId]);
        $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Indexar por nombre de campo
        $result = [];
        foreach ($fields as $field) {
            $result[$field['campo']] = [
                'pos_x' => (float)$field['pos_x'],
                'pos_y' => (float)$field['pos_y'],
                'font_size' => (int)$field['font_size'],
                'font_family' => $field['font_family'],
                'font_style' => $field['font_style'],
                'text_align' => $field['text_align'],
                'max_width' => $field['max_width'] ? (float)$field['max_width'] : null,
                'color' => [
                    'r' => (int)$field['color_r'],
                    'g' => (int)$field['color_g'],
                    'b' => (int)$field['color_b']
                ],
                'prefix' => $field['prefix']
            ];
        }

        return $result;
    }

    /**
     * Genera una imagen PNG de la primera página de un PDF
     * Utiliza Imagick (requiere GhostScript instalado)
     */
    private function generatePreviewImage(string $pdfPath, string $outputDir): ?string
    {
        if (!extension_loaded('imagick')) {
            error_log('Imagick extension not loaded - cannot generate preview');
            return null;
        }

        try {
            $imagick = new \Imagick();

            // Configurar resolución antes de leer el PDF
            $imagick->setResolution(150, 150);

            // Leer solo la primera página del PDF
            $imagick->readImage($pdfPath . '[0]');

            // Convertir a formato PNG
            $imagick->setImageFormat('png');
            $imagick->setImageCompressionQuality(85);

            // Fondo blanco para transparencias
            $imagick->setImageBackgroundColor('white');
            $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);

            // Generar nombre del archivo PNG
            $pdfBasename = pathinfo($pdfPath, PATHINFO_FILENAME);
            $pngFilename = $pdfBasename . '-preview.png';
            $pngPath = $outputDir . '/' . $pngFilename;

            // Guardar imagen
            $imagick->writeImage($pngPath);
            $imagick->clear();
            $imagick->destroy();

            return $pngFilename;
        } catch (\ImagickException $e) {
            error_log('Error generating preview image: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Sube o actualiza la plantilla base (PDF)
     */
    public function uploadBaseTemplate(array $file, int $uploadedBy, ?string $nombre = null): array
    {
        $this->validateUploadedFile($file);

        $filename = 'certificado-base.pdf';
        $destPath = $this->templatesPath . '/base/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new Exception('Error al guardar el archivo');
        }

        // Generar imagen preview PNG
        $previewFilename = $this->generatePreviewImage($destPath, $this->templatesPath . '/base');

        // Verificar si ya existe registro
        $stmt = $this->pdo->prepare("
            SELECT id, version FROM cc_plantillas
            WHERE tipo = 'base' AND activo = 1
        ");
        $stmt->execute();
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        $nombreFinal = $nombre ?: 'Certificado Base ' . date('Y');

        if ($existing) {
            $newVersion = $existing['version'] + 1;
            $stmt = $this->pdo->prepare("
                UPDATE cc_plantillas SET
                    nombre = :nombre,
                    archivo = :archivo,
                    archivo_size = :archivo_size,
                    imagen_preview = :imagen_preview,
                    version = :version,
                    uploaded_by = :uploaded_by,
                    updated_at = UNIX_TIMESTAMP()
                WHERE id = :id
            ");
            $stmt->execute([
                'nombre' => $nombreFinal,
                'archivo' => $filename,
                'archivo_size' => $file['size'],
                'imagen_preview' => $previewFilename,
                'version' => $newVersion,
                'uploaded_by' => $uploadedBy,
                'id' => $existing['id']
            ]);
            $templateId = $existing['id'];
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO cc_plantillas
                (tipo, courseid, nombre, archivo, archivo_size, imagen_preview, version, activo, uploaded_by, created_at, updated_at)
                VALUES ('base', NULL, :nombre, :archivo, :archivo_size, :imagen_preview, 1, 1, :uploaded_by, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
            ");
            $stmt->execute([
                'nombre' => $nombreFinal,
                'archivo' => $filename,
                'archivo_size' => $file['size'],
                'imagen_preview' => $previewFilename,
                'uploaded_by' => $uploadedBy
            ]);
            $templateId = $this->pdo->lastInsertId();
            $newVersion = 1;
        }

        $this->logTemplateAction('upload_base', $templateId, $uploadedBy);

        return [
            'id' => (int)$templateId,
            'tipo' => 'base',
            'nombre' => $nombreFinal,
            'archivo' => $filename,
            'archivo_size' => $file['size'],
            'imagen_preview' => $previewFilename,
            'version' => $newVersion,
            'available_fields' => self::BASE_TEMPLATE_FIELDS
        ];
    }

    /**
     * Sube o actualiza la plantilla de un curso (PDF)
     */
    public function uploadCourseTemplate(int $courseid, array $file, int $uploadedBy, ?string $nombre = null): array
    {
        $this->validateUploadedFile($file);

        // Verificar que el curso existe
        $stmt = $this->pdo->prepare("SELECT id, fullname, shortname FROM mdl_course WHERE id = ?");
        $stmt->execute([$courseid]);
        $course = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$course) {
            throw new Exception("Curso no encontrado: {$courseid}");
        }

        $filename = "curso-{$courseid}-contenidos.pdf";
        $destPath = $this->templatesPath . '/cursos/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new Exception('Error al guardar el archivo');
        }

        // Generar imagen preview PNG
        $previewFilename = $this->generatePreviewImage($destPath, $this->templatesPath . '/cursos');

        // Verificar si ya existe registro
        $stmt = $this->pdo->prepare("
            SELECT id, version FROM cc_plantillas
            WHERE tipo = 'curso' AND courseid = ? AND activo = 1
        ");
        $stmt->execute([$courseid]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        $nombreFinal = $nombre ?: "Contenidos " . $course['shortname'];

        if ($existing) {
            $newVersion = $existing['version'] + 1;
            $stmt = $this->pdo->prepare("
                UPDATE cc_plantillas SET
                    nombre = :nombre,
                    archivo = :archivo,
                    archivo_size = :archivo_size,
                    imagen_preview = :imagen_preview,
                    version = :version,
                    uploaded_by = :uploaded_by,
                    updated_at = UNIX_TIMESTAMP()
                WHERE id = :id
            ");
            $stmt->execute([
                'nombre' => $nombreFinal,
                'archivo' => $filename,
                'archivo_size' => $file['size'],
                'imagen_preview' => $previewFilename,
                'version' => $newVersion,
                'uploaded_by' => $uploadedBy,
                'id' => $existing['id']
            ]);
            $templateId = $existing['id'];
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO cc_plantillas
                (tipo, courseid, nombre, archivo, archivo_size, imagen_preview, version, activo, uploaded_by, created_at, updated_at)
                VALUES ('curso', :courseid, :nombre, :archivo, :archivo_size, :imagen_preview, 1, 1, :uploaded_by, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
            ");
            $stmt->execute([
                'courseid' => $courseid,
                'nombre' => $nombreFinal,
                'archivo' => $filename,
                'archivo_size' => $file['size'],
                'imagen_preview' => $previewFilename,
                'uploaded_by' => $uploadedBy
            ]);
            $templateId = $this->pdo->lastInsertId();
            $newVersion = 1;
        }

        $this->logTemplateAction('upload_course', $templateId, $uploadedBy, ['courseid' => $courseid]);

        return [
            'id' => (int)$templateId,
            'tipo' => 'curso',
            'courseid' => $courseid,
            'course_name' => $course['fullname'],
            'nombre' => $nombreFinal,
            'archivo' => $filename,
            'archivo_size' => $file['size'],
            'imagen_preview' => $previewFilename,
            'version' => $newVersion,
            'available_fields' => self::COURSE_TEMPLATE_FIELDS
        ];
    }

    /**
     * Guarda las coordenadas de los campos de una plantilla
     *
     * NOTA: Los campos de segunda hoja (curso) se guardan asociados a la plantilla BASE,
     * ya que se aplican a TODOS los certificados por igual.
     */
    public function saveTemplateFields(int $plantillaId, array $campos, int $updatedBy): array
    {
        // Verificar que la plantilla existe
        $stmt = $this->pdo->prepare("SELECT id, tipo FROM cc_plantillas WHERE id = ? AND activo = 1");
        $stmt->execute([$plantillaId]);
        $plantilla = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plantilla) {
            throw new Exception("Plantilla no encontrada: {$plantillaId}");
        }

        // Para plantillas de curso, los campos se guardan en la plantilla base
        // pero usando nombres de campo especiales (certificado_id_pagina2)
        $targetPlantillaId = $plantillaId;
        $validFields = array_keys(self::BASE_TEMPLATE_FIELDS);

        if ($plantilla['tipo'] === 'curso') {
            // Obtener ID de la plantilla base
            $stmt = $this->pdo->prepare("SELECT id FROM cc_plantillas WHERE tipo = 'base' AND activo = 1 LIMIT 1");
            $stmt->execute();
            $baseTemplate = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$baseTemplate) {
                throw new Exception("No existe plantilla base para asociar campos de segunda hoja");
            }

            $targetPlantillaId = $baseTemplate['id'];
            $validFields = array_keys(self::COURSE_TEMPLATE_FIELDS);

            // Para campos de segunda hoja, solo eliminamos los campos de segunda hoja existentes
            $stmt = $this->pdo->prepare("DELETE FROM cc_plantillas_campos WHERE plantilla_id = ? AND campo LIKE '%_pagina2'");
            $stmt->execute([$targetPlantillaId]);
        } else {
            // Para plantilla base, eliminamos campos existentes excepto los de página 2
            $stmt = $this->pdo->prepare("DELETE FROM cc_plantillas_campos WHERE plantilla_id = ? AND campo NOT LIKE '%_pagina2'");
            $stmt->execute([$plantillaId]);
        }

        // Insertar nuevos campos
        $insertStmt = $this->pdo->prepare("
            INSERT INTO cc_plantillas_campos
            (plantilla_id, campo, pos_x, pos_y, font_size, font_family, font_style, text_align, max_width, color_r, color_g, color_b, prefix, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
        ");

        $savedFields = [];
        foreach ($campos as $campo => $config) {
            if (!in_array($campo, $validFields)) {
                continue; // Ignorar campos no válidos
            }

            $insertStmt->execute([
                $targetPlantillaId,
                $campo,
                $config['pos_x'] ?? 0,
                $config['pos_y'] ?? 0,
                $config['font_size'] ?? 12,
                $config['font_family'] ?? 'arial',
                $config['font_style'] ?? 'normal',
                $config['text_align'] ?? 'left',
                $config['max_width'] ?? null,
                $config['color']['r'] ?? 0,
                $config['color']['g'] ?? 0,
                $config['color']['b'] ?? 0,
                $config['prefix'] ?? null
            ]);

            $savedFields[$campo] = $config;
        }

        // Actualizar timestamp de la plantilla original
        $stmt = $this->pdo->prepare("UPDATE cc_plantillas SET updated_at = UNIX_TIMESTAMP() WHERE id = ?");
        $stmt->execute([$plantillaId]);

        $this->logTemplateAction('update_fields', $plantillaId, $updatedBy, ['fields' => array_keys($savedFields)]);

        return $savedFields;
    }

    /**
     * Obtiene los campos de segunda hoja (asociados a la plantilla base)
     */
    public function getSecondPageFields(): array
    {
        // Los campos de segunda hoja están asociados a la plantilla base con sufijo _pagina2
        $stmt = $this->pdo->prepare("
            SELECT p.id as base_template_id
            FROM cc_plantillas p
            WHERE p.tipo = 'base' AND p.activo = 1
            LIMIT 1
        ");
        $stmt->execute();
        $baseTemplate = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$baseTemplate) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT
                campo,
                pos_x,
                pos_y,
                font_size,
                font_family,
                font_style,
                text_align,
                max_width,
                color_r,
                color_g,
                color_b,
                prefix
            FROM cc_plantillas_campos
            WHERE plantilla_id = ? AND campo LIKE '%_pagina2'
        ");
        $stmt->execute([$baseTemplate['base_template_id']]);
        $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($fields as $field) {
            $result[$field['campo']] = [
                'pos_x' => (float)$field['pos_x'],
                'pos_y' => (float)$field['pos_y'],
                'font_size' => (int)$field['font_size'],
                'font_family' => $field['font_family'],
                'font_style' => $field['font_style'],
                'text_align' => $field['text_align'],
                'max_width' => $field['max_width'] ? (float)$field['max_width'] : null,
                'color' => [
                    'r' => (int)$field['color_r'],
                    'g' => (int)$field['color_g'],
                    'b' => (int)$field['color_b']
                ],
                'prefix' => $field['prefix']
            ];
        }

        return $result;
    }

    /**
     * Elimina la plantilla de un curso
     */
    public function deleteCourseTemplate(int $courseid, int $deletedBy): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT id, archivo FROM cc_plantillas
            WHERE tipo = 'curso' AND courseid = ? AND activo = 1
        ");
        $stmt->execute([$courseid]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            throw new Exception("Plantilla no encontrada para el curso: {$courseid}");
        }

        // Soft delete
        $stmt = $this->pdo->prepare("
            UPDATE cc_plantillas SET
                activo = 0,
                updated_at = UNIX_TIMESTAMP()
            WHERE id = ?
        ");
        $stmt->execute([$template['id']]);

        // Eliminar archivo físico
        $filePath = $this->templatesPath . '/cursos/' . $template['archivo'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $this->logTemplateAction('delete_course', $template['id'], $deletedBy, ['courseid' => $courseid]);

        return true;
    }

    /**
     * Obtiene el path de una plantilla para descarga
     */
    public function getTemplateForDownload(int $templateId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, tipo, courseid, nombre, archivo, archivo_size
            FROM cc_plantillas
            WHERE id = ? AND activo = 1
        ");
        $stmt->execute([$templateId]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            return null;
        }

        if ($template['tipo'] === 'base') {
            $template['file_path'] = $this->templatesPath . '/base/' . $template['archivo'];
        } else {
            $template['file_path'] = $this->templatesPath . '/cursos/' . $template['archivo'];
        }

        if (!file_exists($template['file_path'])) {
            return null;
        }

        return $template;
    }

    /**
     * Genera un PDF de preview con datos de ejemplo
     */
    public function generatePreview(int $courseid): string
    {
        // Verificar plantilla base
        $baseTemplate = $this->getBaseTemplateInfo();
        if (!$baseTemplate) {
            throw new Exception('No hay plantilla base configurada');
        }

        // Verificar plantilla del curso
        $courseTemplate = $this->getCourseTemplateInfo($courseid);
        if (!$courseTemplate) {
            throw new Exception("No hay plantilla configurada para el curso: {$courseid}");
        }

        // Obtener datos del curso
        $stmt = $this->pdo->prepare("SELECT fullname, shortname FROM mdl_course WHERE id = ?");
        $stmt->execute([$courseid]);
        $course = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$course) {
            throw new Exception("Curso no encontrado: {$courseid}");
        }

        // Datos de ejemplo
        $previewData = [
            'participante' => 'Juan Carlos Pérez García',
            'documento' => 'CC 1.234.567.890',
            'curso' => 'EN EL CURSO ' . $course['fullname'],
            'intensidad' => 'INTENSIDAD 40 HORAS',
            'fecha' => 'BOGOTÁ, COLOMBIA, ' . $this->formatMonth(time()),
            'certificado_id' => 'CV-1234',
            'certificado_id_pagina2' => 'CV-1234'  // Mismo ID para segunda página
        ];

        return $this->generateCertificatePdf($baseTemplate, $courseTemplate, $previewData);
    }

    /**
     * Genera un certificado PDF completo y lo guarda en storage/pdfs
     *
     * @param array $certificateData Datos del certificado de la BD
     * @return string Path del PDF generado
     */
    public function generateCertificateForUser(array $certificateData): string
    {
        $baseTemplate = $this->getBaseTemplateInfo();
        if (!$baseTemplate) {
            throw new Exception('No hay plantilla base configurada');
        }

        $courseTemplate = $this->getCourseTemplateInfo($certificateData['courseid']);

        // Preparar datos con prefijos según configuración de campos
        $numeroCertificado = $certificateData['numero_certificado'] ?? '';
        $baseFields = $this->getTemplateFields($baseTemplate['id']);

        // Obtener prefijos configurados
        $cursoPrefix = $baseFields['curso']['prefix'] ?? '';
        $fechaPrefix = $baseFields['fecha']['prefix'] ?? '';

        $data = [
            'participante' => strtoupper(($certificateData['firstname'] ?? '') . ' ' . ($certificateData['lastname'] ?? '')),
            'documento' => 'CC ' . $this->formatDocument($certificateData['idnumber'] ?? ''),
            'curso' => $cursoPrefix . ($certificateData['course_name'] ?? ''),
            'intensidad' => 'INTENSIDAD ' . ($certificateData['intensidad'] ?? 40) . ' HORAS',
            'fecha' => $fechaPrefix . $this->formatMonth($certificateData['fecha_emision'] ?? time()),
            'certificado_id' => $numeroCertificado,
            'certificado_id_pagina2' => $numeroCertificado  // Mismo ID para segunda página
        ];

        // Generar nombre de archivo basado en número de certificado
        $filename = $this->generateCertificateFilename($numeroCertificado);
        $outputPath = $this->pdfsPath . '/' . $filename;

        // Generar PDF y guardarlo en storage/pdfs
        $this->generateCertificatePdf($baseTemplate, $courseTemplate, $data, $outputPath);

        // Log de generación
        $this->logCertificateGeneration($certificateData, $filename);

        return $outputPath;
    }

    /**
     * Genera nombre de archivo para certificado
     */
    private function generateCertificateFilename(string $numeroCertificado): string
    {
        $numero = preg_replace('/[^A-Za-z0-9]/', '_', $numeroCertificado);
        $timestamp = date('YmdHis');
        return "certificado_{$numero}_{$timestamp}.pdf";
    }

    /**
     * Obtiene el path de un certificado existente
     *
     * @param string $numeroCertificado Número de certificado (ej: CV-3490)
     * @return string|null Path del PDF o null si no existe
     */
    public function getCertificatePath(string $numeroCertificado): ?string
    {
        $pattern = $this->pdfsPath . "/certificado_" . preg_replace('/[^A-Za-z0-9]/', '_', $numeroCertificado) . "_*.pdf";
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
     * Registra la generación del certificado en el log
     */
    private function logCertificateGeneration(array $data, string $filename): void
    {
        $logFile = LOG_PATH . '/certificate-generation.log';
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $logEntry = sprintf(
            "[%s] Generated certificate %s for user %s %s (course: %s) - File: %s\n",
            date('Y-m-d H:i:s'),
            $data['numero_certificado'] ?? 'unknown',
            $data['firstname'] ?? '',
            $data['lastname'] ?? '',
            $data['course_name'] ?? '',
            $filename
        );

        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }

    /**
     * Genera el PDF del certificado combinando plantillas
     *
     * @param array $baseTemplate Datos de la plantilla base
     * @param array|null $courseTemplate Datos de la plantilla del curso (opcional)
     * @param array $data Datos a insertar en el certificado
     * @param string|null $outputPath Path de salida (si es null, usa directorio temporal)
     * @return string Path del PDF generado
     */
    private function generateCertificatePdf(array $baseTemplate, ?array $courseTemplate, array $data, ?string $outputPath = null): string
    {
        // Resetear tracking de fuentes registradas para este nuevo PDF
        $this->registeredFonts = [];

        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);

        // Página 1: Plantilla base
        $basePath = $this->templatesPath . '/base/' . $baseTemplate['archivo'];
        $pdf->setSourceFile($basePath);
        $tplId = $pdf->importPage(1);

        // Obtener dimensiones reales del template para crear página del mismo tamaño
        $size = $pdf->getTemplateSize($tplId);
        $orientation = $size['width'] > $size['height'] ? 'L' : 'P';

        $pdf->AddPage($orientation, [$size['width'], $size['height']]);
        $pdf->useTemplate($tplId, 0, 0, $size['width'], $size['height']);

        // Insertar campos en página 1 (excluyendo campos de segunda página con sufijo _pagina2)
        $baseFields = $this->getTemplateFields($baseTemplate['id']);
        foreach ($baseFields as $campo => $config) {
            // Excluir campos destinados a la segunda página
            if (str_ends_with($campo, '_pagina2')) {
                continue;
            }
            if (isset($data[$campo])) {
                $this->insertText($pdf, $data[$campo], $config);
            }
        }

        // Página 2: Plantilla del curso (si existe)
        if ($courseTemplate) {
            $coursePath = $this->templatesPath . '/cursos/' . $courseTemplate['archivo'];
            if (file_exists($coursePath)) {
                $pdf->setSourceFile($coursePath);
                $tplId = $pdf->importPage(1);

                // Obtener dimensiones del template de curso
                $size = $pdf->getTemplateSize($tplId);
                $orientation = $size['width'] > $size['height'] ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                $pdf->useTemplate($tplId, 0, 0, $size['width'], $size['height']);

                // Insertar campos de segunda página (almacenados en plantilla base con sufijo _pagina2)
                $secondPageFields = $this->getSecondPageFields();
                foreach ($secondPageFields as $campo => $config) {
                    if (isset($data[$campo])) {
                        $this->insertText($pdf, $data[$campo], $config);
                    }
                }
            }
        }

        // Si no se especifica path de salida, usar directorio temporal (para previews)
        if ($outputPath === null) {
            $outputPath = $this->tempPath . '/cert-' . uniqid() . '.pdf';
        }

        $pdf->Output('F', $outputPath);

        return $outputPath;
    }

    // Mapeo de fuentes del frontend a fuentes FPDF
    // Fuentes personalizadas: cinzel, ttnorms (norms)
    // Fuentes estándar: Arial/Helvetica, Times, Courier
    private const FONT_MAP = [
        'arial' => 'Arial',
        'helvetica' => 'Helvetica',
        'times' => 'Times',
        'courier' => 'Courier',
        'cinzel' => 'cinzel',     // Fuente personalizada
        'norms' => 'ttnorms',     // Fuente personalizada (TT Norms)
    ];

    // Fuentes que requieren AddFont() (fuentes personalizadas)
    private const CUSTOM_FONTS = ['cinzel', 'ttnorms'];

    // Mapeo de estilos de fuente
    private const STYLE_MAP = [
        'normal' => '',
        'bold' => 'B',
        'italic' => 'I',
        'underline' => 'U',
        'bolditalic' => 'BI',
    ];

    // Track de fuentes ya registradas en el PDF
    private array $registeredFonts = [];

    /**
     * Registra una fuente personalizada en el PDF si no está ya registrada
     *
     * NOTA: FPDF_FONTPATH se define en config.php ANTES del autoload
     * para que FPDF encuentre las fuentes personalizadas en storage/fonts/fpdf/
     */
    private function registerCustomFont(Fpdi $pdf, string $fontName, string $style): void
    {
        $key = $fontName . '_' . $style;

        if (isset($this->registeredFonts[$key])) {
            return;
        }

        // Determinar el archivo de fuente según el nombre y estilo
        $fontFile = match (true) {
            $fontName === 'cinzel' && $style === 'B' => 'cinzelb.php',
            $fontName === 'cinzel' => 'cinzel.php',
            $fontName === 'ttnorms' && $style === 'BI' => 'ttnormsbi.php',
            $fontName === 'ttnorms' && $style === 'B' => 'ttnormsb.php',
            $fontName === 'ttnorms' && $style === 'I' => 'ttnormsi.php',
            $fontName === 'ttnorms' => 'ttnorms.php',
            default => null
        };

        if ($fontFile) {
            $pdf->AddFont($fontName, $style, $fontFile);
            $this->registeredFonts[$key] = true;
        }
    }

    /**
     * Inserta texto en el PDF según la configuración
     *
     * La coordenada (pos_x, pos_y) representa el punto de anclaje del texto:
     * - left: el texto comienza en pos_x (lado izquierdo del texto)
     * - center: el texto se centra en pos_x (centro del texto)
     * - right: el texto termina en pos_x (lado derecho del texto)
     *
     * Si max_width está configurado, el texto se ajustará a ese ancho con
     * saltos de línea automáticos y un interlineado del 120%.
     */
    private function insertText(Fpdi $pdf, string $text, array $config): void
    {
        // Mapear fuente del frontend a fuente FPDF
        $fontFamily = strtolower($config['font_family'] ?? 'arial');
        $mappedFont = self::FONT_MAP[$fontFamily] ?? 'Arial';

        // Mapear estilo
        $fontStyle = strtolower($config['font_style'] ?? 'normal');
        $mappedStyle = self::STYLE_MAP[$fontStyle] ?? '';

        // Si es fuente personalizada, registrarla primero
        if (in_array($mappedFont, self::CUSTOM_FONTS)) {
            // Cinzel no tiene italic, usar normal si se pide italic
            if ($mappedFont === 'cinzel' && ($mappedStyle === 'I' || $mappedStyle === 'BI')) {
                $mappedStyle = ($mappedStyle === 'BI') ? 'B' : '';
            }
            $this->registerCustomFont($pdf, $mappedFont, $mappedStyle);
        }

        $fontSize = $config['font_size'] ?? 12;

        // Configurar fuente ANTES de calcular el ancho del texto
        $pdf->SetFont($mappedFont, $mappedStyle, $fontSize);

        // Configurar color
        $pdf->SetTextColor(
            $config['color']['r'] ?? 0,
            $config['color']['g'] ?? 0,
            $config['color']['b'] ?? 0
        );

        // Convertir UTF-8 a ISO-8859-1 para FPDF
        $text = $this->utf8ToLatin1($text);

        // Calcular posición X según alineación
        $posX = (float)$config['pos_x'];
        $posY = (float)$config['pos_y'];
        $align = strtolower($config['text_align'] ?? 'left');
        $maxWidth = isset($config['max_width']) ? (float)$config['max_width'] : null;

        // Si hay max_width, usar MultiCell para texto multilínea con interlineado
        if ($maxWidth && $maxWidth > 0) {
            // Calcular altura de línea: 120% del tamaño de fuente
            // fontSize está en puntos, convertir a mm: 1pt = 0.352778mm
            $lineHeightMm = ($fontSize * 0.352778) * 1.20;

            // Mapear alineación para MultiCell: L, C, R
            $multiCellAlign = match ($align) {
                'center' => 'C',
                'right' => 'R',
                default => 'L'
            };

            // Ajustar posX según alineación para MultiCell
            // MultiCell siempre empieza desde posX, pero alinea el contenido internamente
            if ($align === 'center') {
                $posX = $posX - ($maxWidth / 2);
            } elseif ($align === 'right') {
                $posX = $posX - $maxWidth;
            }

            $pdf->SetXY($posX, $posY);
            $pdf->MultiCell($maxWidth, $lineHeightMm, $text, 0, $multiCellAlign);
        } else {
            // Texto de una sola línea - usar Write
            $textWidth = $pdf->GetStringWidth($text);

            if ($align === 'center') {
                $posX = $posX - ($textWidth / 2);
            } elseif ($align === 'right') {
                $posX = $posX - $textWidth;
            }

            $pdf->SetXY($posX, $posY);
            $pdf->Write(0, $text);
        }
    }

    /**
     * Obtiene información de la plantilla base
     */
    private function getBaseTemplateInfo(): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, archivo FROM cc_plantillas
            WHERE tipo = 'base' AND activo = 1
            LIMIT 1
        ");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Obtiene información de la plantilla de un curso
     */
    private function getCourseTemplateInfo(int $courseid): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, archivo FROM cc_plantillas
            WHERE tipo = 'curso' AND courseid = ? AND activo = 1
            LIMIT 1
        ");
        $stmt->execute([$courseid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Valida un archivo PDF subido
     */
    private function validateUploadedFile(array $file): void
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE => 'El archivo excede el tamaño máximo permitido por PHP',
                UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo del formulario',
                UPLOAD_ERR_PARTIAL => 'El archivo se subió parcialmente',
                UPLOAD_ERR_NO_FILE => 'No se subió ningún archivo',
                UPLOAD_ERR_NO_TMP_DIR => 'Falta carpeta temporal',
                UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo',
                UPLOAD_ERR_EXTENSION => 'Extensión de PHP detuvo la subida'
            ];
            throw new Exception($errors[$file['error']] ?? 'Error desconocido al subir archivo');
        }

        // Verificar extensión
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($extension !== 'pdf') {
            throw new Exception('Solo se permiten archivos .pdf');
        }

        // Verificar tamaño
        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new Exception('El archivo no puede superar 10 MB');
        }

        // Verificar MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            throw new Exception('Tipo de archivo no permitido: ' . $mimeType);
        }

        // Verificar que es un PDF válido (verificar magic bytes)
        $handle = fopen($file['tmp_name'], 'rb');
        $header = fread($handle, 5);
        fclose($handle);

        if ($header !== '%PDF-') {
            throw new Exception('El archivo no es un PDF válido');
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
     * Formatea el número de documento con puntos de miles
     */
    private function formatDocument(string $idnumber): string
    {
        $number = preg_replace('/[^0-9]/', '', $idnumber);
        return number_format((float)$number, 0, '', '.');
    }

    /**
     * Formatea una fecha como "Mes de Año"
     */
    private function formatMonth(int $timestamp): string
    {
        $meses = [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre'
        ];

        $mes = (int)date('n', $timestamp);
        $año = date('Y', $timestamp);

        return $meses[$mes] . ' de ' . $año;
    }

    /**
     * Formatea tamaño de archivo
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }

    /**
     * Obtiene las dimensiones de un PDF en milímetros
     *
     * @param string $pdfPath Ruta al archivo PDF
     * @return array{width: float, height: float}|null Dimensiones en mm o null si hay error
     */
    private function getPdfDimensions(string $pdfPath): ?array
    {
        if (!file_exists($pdfPath)) {
            return null;
        }

        try {
            $pdf = new Fpdi();
            $pdf->setSourceFile($pdfPath);
            $tplId = $pdf->importPage(1);
            $size = $pdf->getTemplateSize($tplId);

            return [
                'width' => round($size['width'], 2),
                'height' => round($size['height'], 2)
            ];
        } catch (\Exception $e) {
            error_log('Error getting PDF dimensions: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Registra una acción en el log
     */
    private function logTemplateAction(string $action, int $templateId, int $userId, array $extra = []): void
    {
        $logFile = LOG_PATH . '/template-actions.log';
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $logEntry = sprintf(
            "[%s] Action: %s | Template: %d | User: %d | Extra: %s\n",
            date('Y-m-d H:i:s'),
            $action,
            $templateId,
            $userId,
            json_encode($extra)
        );

        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}
