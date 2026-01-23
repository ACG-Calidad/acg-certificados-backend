<?php
/**
 * Router Principal - API REST de Certificados ACG
 */

// Cargar configuración
require_once __DIR__ . '/../config/config.php';

use ACG\Certificados\Utils\Response;
use ACG\Certificados\Services\MoodleService;
use ACG\Certificados\Services\PdfService;
use ACG\Certificados\Services\TemplateService;

// Manejar preflight CORS
Response::handlePreflight();

// Obtener URI y método
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Remover query string
$requestUri = strtok($requestUri, '?');

// Remover prefijo /certificados (cuando está en subdirectorio)
$requestUri = preg_replace('#^/certificados#', '', $requestUri);

// Remover /api si existe
$requestUri = preg_replace('#^/api#', '', $requestUri);

// ============================================================================
// ROUTING
// ============================================================================

try {
    match(true) {
        // ========================================================================
        // Root - Info del API
        // ========================================================================
        $requestMethod === 'GET' && $requestUri === '/' => handleRoot(),

        // ========================================================================
        // Certificates - Listar por usuario
        // ========================================================================
        $requestMethod === 'GET' && preg_match('#^/certificates/user/(\d+)$#', $requestUri, $matches) =>
            handleCertificatesByUser((int)$matches[1]),

        // ========================================================================
        // Certificates - Descargar PDF
        // ========================================================================
        $requestMethod === 'GET' && preg_match('#^/certificates/(\d+)/download$#', $requestUri, $matches) =>
            handleCertificateDownload((int)$matches[1]),

        // ========================================================================
        // Certificates - Validar por código
        // ========================================================================
        $requestMethod === 'GET' && preg_match('#^/certificates/validate/([A-Za-z0-9-]+)$#', $requestUri, $matches) =>
            handleCertificateValidate($matches[1]),

        // ========================================================================
        // Admin - Dashboard
        // ========================================================================
        $requestMethod === 'GET' && $requestUri === '/admin/dashboard' => handleAdminDashboard(),

        // ========================================================================
        // Admin - Certificados pendientes
        // ========================================================================
        $requestMethod === 'GET' && $requestUri === '/admin/certificates/pending' => handlePendingCertificates(),

        // ========================================================================
        // Admin - Aprobar y generar certificados
        // ========================================================================
        $requestMethod === 'POST' && $requestUri === '/admin/certificates/approve' => handleApproveCertificates(),

        // ========================================================================
        // Admin - Conteos para badges del sidebar
        // ========================================================================
        $requestMethod === 'GET' && $requestUri === '/admin/badges' => handleAdminBadges(),

        // ========================================================================
        // Admin - Notificaciones pendientes
        // ========================================================================
        $requestMethod === 'GET' && $requestUri === '/admin/notifications/pending' => handlePendingNotifications(),

        // ========================================================================
        // Admin - Enviar notificaciones
        // ========================================================================
        $requestMethod === 'POST' && $requestUri === '/admin/notifications/send' => handleSendNotifications(),

        // ========================================================================
        // Admin - Listar todos los certificados generados
        // ========================================================================
        $requestMethod === 'GET' && $requestUri === '/admin/certificates/generated' => handleGeneratedCertificates(),

        // ========================================================================
        // Admin - Regenerar certificados (actualiza PDF y fecha)
        // ========================================================================
        $requestMethod === 'POST' && $requestUri === '/admin/certificates/regenerate' => handleRegenerateCertificates(),

        // ========================================================================
        // Admin - Descargar múltiples certificados como ZIP
        // ========================================================================
        $requestMethod === 'POST' && $requestUri === '/admin/certificates/download-zip' => handleDownloadCertificatesZip(),

        // ========================================================================
        // Admin - Obtener historial de generaciones de un certificado
        // ========================================================================
        $requestMethod === 'GET' && preg_match('#^/admin/certificates/(\d+)/generations$#', $requestUri, $matches) =>
            handleGetCertificateGenerations((int)$matches[1]),

        // ========================================================================
        // Admin - Exportar reporte de certificados (Excel/CSV)
        // ========================================================================
        $requestMethod === 'GET' && $requestUri === '/admin/report/export' => handleExportReport(),

        // ========================================================================
        // Admin - Configuración del sistema (GET)
        // ========================================================================
        $requestMethod === 'GET' && $requestUri === '/admin/settings' => handleGetSettings(),

        // ========================================================================
        // Admin - Configuración del sistema (PUT)
        // ========================================================================
        $requestMethod === 'PUT' && $requestUri === '/admin/settings' => handleUpdateSettings(),

        // ========================================================================
        // Admin - Plantillas: Listar todas
        // ========================================================================
        $requestMethod === 'GET' && $requestUri === '/admin/templates' => handleGetTemplates(),

        // ========================================================================
        // Admin - Plantillas: Subir/actualizar plantilla base
        // ========================================================================
        $requestMethod === 'POST' && $requestUri === '/admin/templates/base' => handleUploadBaseTemplate(),

        // ========================================================================
        // Admin - Plantillas: Subir/actualizar plantilla de curso
        // ========================================================================
        $requestMethod === 'POST' && preg_match('#^/admin/templates/course/(\d+)$#', $requestUri, $matches) =>
            handleUploadCourseTemplate((int)$matches[1]),

        // ========================================================================
        // Admin - Plantillas: Eliminar plantilla de curso
        // ========================================================================
        $requestMethod === 'DELETE' && preg_match('#^/admin/templates/course/(\d+)$#', $requestUri, $matches) =>
            handleDeleteCourseTemplate((int)$matches[1]),

        // ========================================================================
        // Admin - Plantillas: Descargar plantilla
        // ========================================================================
        $requestMethod === 'GET' && preg_match('#^/admin/templates/(\d+)/download$#', $requestUri, $matches) =>
            handleDownloadTemplate((int)$matches[1]),

        // ========================================================================
        // Admin - Plantillas: Obtener imagen PNG de preview
        // ========================================================================
        $requestMethod === 'GET' && preg_match('#^/admin/templates/(\d+)/preview-image$#', $requestUri, $matches) =>
            handleGetTemplatePreviewImage((int)$matches[1]),

        // ========================================================================
        // Admin - Plantillas: Preview de certificado
        // ========================================================================
        $requestMethod === 'POST' && $requestUri === '/admin/templates/preview' => handleTemplatePreview(),

        // ========================================================================
        // Admin - Plantillas: Guardar coordenadas de campos
        // ========================================================================
        $requestMethod === 'PUT' && preg_match('#^/admin/templates/(\d+)/fields$#', $requestUri, $matches) =>
            handleSaveTemplateFields((int)$matches[1]),

        // ========================================================================
        // Admin - Plantillas: Obtener campos disponibles
        // ========================================================================
        $requestMethod === 'GET' && $requestUri === '/admin/templates/available-fields' => handleGetAvailableFields(),

        // ========================================================================
        // 404 - Ruta no encontrada
        // ========================================================================
        default => Response::error('Endpoint no encontrado', 404, 'NOT_FOUND')
    };

} catch (\Exception $e) {
    Response::error(
        'Error interno del servidor: ' . $e->getMessage(),
        500,
        'INTERNAL_ERROR',
        DEBUG_MODE ? ['trace' => $e->getTraceAsString()] : []
    );
}

// ============================================================================
// HANDLERS
// ============================================================================

/**
 * GET / - Información del API
 */
function handleRoot(): void
{
    Response::success([
        'name' => 'ACG Certificados API',
        'version' => '1.0.0',
        'environment' => ENVIRONMENT,
        'endpoints' => [
            'GET /api/certificates/user/{userid}' => 'Listar certificados de un usuario',
            'GET /api/certificates/{id}/download' => 'Descargar PDF de certificado'
        ],
        'note' => 'La validación de tokens SSO se hace directamente desde el frontend al Web Service de Moodle',
        'database' => testDatabaseConnection(),
        'moodle' => [
            'url' => MOODLE_URL,
            'token_configured' => !empty(MOODLE_TOKEN)
        ]
    ], 'API funcionando correctamente');
}

/**
 * POST /api/auth/validate - Validar token SSO
 */
function handleAuthValidate(): void
{
    Response::validateMethod('POST');

    $body = Response::getJsonBody();

    // Validar que se envió el token
    if (!isset($body['token']) || empty($body['token'])) {
        Response::error('Token no proporcionado', 400, 'MISSING_TOKEN');
    }

    $token = trim($body['token']);

    // Validar con Moodle
    $moodleService = new MoodleService();
    $userData = $moodleService->validateSSOToken($token);

    if (!$userData) {
        Response::error('Token inválido o expirado', 401, 'INVALID_TOKEN');
    }

    // Retornar datos del usuario
    Response::success([
        'user' => [
            'id' => $userData['userid'] ?? null,
            'username' => $userData['username'] ?? null,
            'firstname' => $userData['firstname'] ?? null,
            'lastname' => $userData['lastname'] ?? null,
            'email' => $userData['email'] ?? null
        ]
    ], 'Token válido');
}

/**
 * GET /api/certificates/user/{userid} - Listar certificados de un usuario
 */
function handleCertificatesByUser(int $userid): void
{
    Response::validateMethod('GET');

    // Conectar a BD
    $pdo = getDatabaseConnection();

    // Consultar certificados del usuario
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.numero_certificado,
            c.fecha_emision,
            c.intensidad,
            c.calificacion,
            c.estado,
            co.fullname as course_name,
            co.shortname as course_shortname
        FROM cc_certificados c
        INNER JOIN mdl_course co ON c.courseid = co.id
        WHERE c.userid = :userid
        ORDER BY c.fecha_emision DESC
    ");

    $stmt->execute(['userid' => $userid]);
    $certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatear fechas
    foreach ($certificates as &$cert) {
        $cert['fecha_emision_formatted'] = date('Y-m-d H:i:s', $cert['fecha_emision']);
    }

    Response::success([
        'certificates' => $certificates,
        'total' => count($certificates)
    ]);
}

/**
 * GET /api/certificates/{id}/download - Descargar PDF de certificado
 */
function handleCertificateDownload(int $id): void
{
    Response::validateMethod('GET');

    $pdo = getDatabaseConnection();

    // Obtener datos completos del certificado
    $stmt = $pdo->prepare("
        SELECT
            c.*,
            u.firstname,
            u.lastname,
            u.email,
            u.idnumber,
            co.fullname as course_name,
            co.shortname as course_shortname
        FROM cc_certificados c
        INNER JOIN mdl_user u ON c.userid = u.id
        INNER JOIN mdl_course co ON c.courseid = co.id
        WHERE c.id = :id
    ");

    $stmt->execute(['id' => $id]);
    $certificate = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$certificate) {
        Response::error('Certificado no encontrado', 404, 'NOT_FOUND');
    }

    try {
        // Inicializar servicio de plantillas (genera PDFs con plantillas personalizadas)
        $templateService = new TemplateService($pdo);

        // Verificar si ya existe PDF generado
        $existingPdf = $templateService->getCertificatePath($certificate['numero_certificado']);

        if ($existingPdf && file_exists($existingPdf)) {
            // Usar PDF existente (caché)
            $pdfPath = $existingPdf;
        } else {
            // Generar nuevo PDF usando plantillas
            $pdfPath = $templateService->generateCertificateForUser($certificate);
        }

        // Registrar descarga en log
        logDownload($certificate, $_SERVER['REMOTE_ADDR'] ?? 'unknown');

        // Enviar PDF al navegador
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="certificado_' . $certificate['numero_certificado'] . '.pdf"');
        header('Content-Length: ' . filesize($pdfPath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        readfile($pdfPath);
        exit;

    } catch (Exception $e) {
        Response::error(
            'Error generando certificado PDF',
            500,
            'PDF_GENERATION_ERROR',
            DEBUG_MODE ? ['error' => $e->getMessage()] : []
        );
    }
}

/**
 * GET /api/certificates/validate/{code} - Validar certificado por código (público)
 *
 * Valida certificados con estado 'generado' o 'notificado' (ya emitidos).
 * Este endpoint es público, no requiere autenticación.
 */
function handleCertificateValidate(string $code): void
{
    Response::validateMethod('GET');

    $pdo = getDatabaseConnection();

    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.numero_certificado,
            c.fecha_emision,
            c.intensidad,
            c.calificacion,
            c.estado,
            u.firstname,
            u.lastname,
            co.fullname as course_name,
            co.shortname as course_shortname
        FROM cc_certificados c
        INNER JOIN mdl_user u ON c.userid = u.id
        INNER JOIN mdl_course co ON c.courseid = co.id
        WHERE c.numero_certificado = :code
        AND c.estado IN ('generado', 'notificado')
    ");

    $stmt->execute(['code' => $code]);
    $certificate = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$certificate) {
        Response::success([
            'valid' => false
        ], 'Certificado no encontrado o no válido');
        return;
    }

    $certificate['fecha_emision_formatted'] = date('d/m/Y', $certificate['fecha_emision']);
    $certificate['participant_name'] = $certificate['firstname'] . ' ' . $certificate['lastname'];

    // Remover campos sensibles de la respuesta
    unset($certificate['firstname'], $certificate['lastname']);

    Response::success([
        'valid' => true,
        'certificate' => $certificate
    ], 'Certificado válido');
}

/**
 * GET /api/admin/dashboard - Estadísticas del dashboard
 */
function handleAdminDashboard(): void
{
    Response::validateMethod('GET');

    $pdo = getDatabaseConnection();

    // Estadísticas generales
    $stats = [];

    // Total de certificados (aprobados, generados o notificados - excluyendo pendientes)
    $stmt = $pdo->query("SELECT COUNT(*) FROM cc_certificados WHERE estado IN ('aprobado', 'generado', 'notificado')");
    $stats['total_certificates'] = (int)$stmt->fetchColumn();

    // Pendientes (usuarios con calificación >= 80% que no tienen certificado)
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM mdl_grade_grades gg
        INNER JOIN mdl_grade_items gi ON gg.itemid = gi.id AND gi.itemtype = 'course'
        LEFT JOIN cc_certificados cert ON gg.userid = cert.userid AND gi.courseid = cert.courseid
        WHERE gg.finalgrade IS NOT NULL
        AND gg.finalgrade >= 80
        AND cert.id IS NULL
    ");
    $stats['pending_certificates'] = (int)$stmt->fetchColumn();

    // Certificados de este mes
    $firstDayOfMonth = strtotime('first day of this month midnight');
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM cc_certificados
        WHERE estado IN ('aprobado', 'generado', 'notificado')
        AND fecha_emision >= :first_day
    ");
    $stmt->execute(['first_day' => $firstDayOfMonth]);
    $stats['this_month_certificates'] = (int)$stmt->fetchColumn();

    // Notificaciones pendientes (certificados generados pero no notificados)
    // El estado 'generado' indica que el PDF está listo pero no se ha enviado notificación
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM cc_certificados
        WHERE estado = 'generado'
    ");
    $stats['pending_notifications'] = (int)$stmt->fetchColumn();

    // Certificados por mes (últimos 6 meses)
    $monthlyData = [];
    for ($i = 5; $i >= 0; $i--) {
        $monthStart = strtotime("-$i months", strtotime('first day of this month midnight'));
        $monthEnd = strtotime("+1 month", $monthStart);
        $monthName = date('M', $monthStart);

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM cc_certificados
            WHERE estado IN ('aprobado', 'generado', 'notificado')
            AND fecha_emision >= :start
            AND fecha_emision < :end
        ");
        $stmt->execute(['start' => $monthStart, 'end' => $monthEnd]);

        $monthlyData[] = [
            'month' => $monthName,
            'count' => (int)$stmt->fetchColumn()
        ];
    }

    // Top 5 cursos con más certificados
    $stmt = $pdo->query("
        SELECT
            c.courseid as course_id,
            co.fullname as course_name,
            co.shortname as course_shortname,
            COUNT(*) as certificate_count
        FROM cc_certificados c
        INNER JOIN mdl_course co ON c.courseid = co.id
        WHERE c.estado IN ('aprobado', 'generado', 'notificado')
        GROUP BY c.courseid, co.fullname, co.shortname
        ORDER BY certificate_count DESC
        LIMIT 5
    ");
    $topCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Actividad reciente
    $stmt = $pdo->query("
        SELECT
            c.id,
            'certificate_generated' as action,
            CONCAT('Certificado generado: ', u.firstname, ' ', u.lastname, ' - ', co.shortname) as description,
            FROM_UNIXTIME(c.fecha_emision) as timestamp,
            'Sistema' as user_name
        FROM cc_certificados c
        INNER JOIN mdl_user u ON c.userid = u.id
        INNER JOIN mdl_course co ON c.courseid = co.id
        ORDER BY c.fecha_emision DESC
        LIMIT 10
    ");
    $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

    Response::success([
        'stats' => $stats,
        'monthly_data' => $monthlyData,
        'top_courses' => $topCourses,
        'recent_activity' => $recentActivity
    ]);
}

/**
 * GET /api/admin/certificates/pending - Usuarios pendientes de certificar
 *
 * Criterio: Usuarios con calificación en un curso que NO tienen certificado generado.
 * La calificación mínima (80%) se filtra en el frontend para mayor flexibilidad.
 */
function handlePendingCertificates(): void
{
    Response::validateMethod('GET');

    $pdo = getDatabaseConnection();

    // Usuarios con calificación en cursos que no tienen certificado
    // Se usa timemodified de mdl_grade_grades como fecha de última calificación
    $stmt = $pdo->query("
        SELECT
            u.id as userid,
            u.firstname,
            u.lastname,
            u.email,
            u.idnumber,
            c.id as course_id,
            c.fullname as course_name,
            c.shortname as course_shortname,
            gg.finalgrade as grade,
            gg.timemodified as grade_date
        FROM mdl_grade_grades gg
        INNER JOIN mdl_grade_items gi ON gg.itemid = gi.id AND gi.itemtype = 'course'
        INNER JOIN mdl_user u ON gg.userid = u.id
        INNER JOIN mdl_course c ON gi.courseid = c.id
        LEFT JOIN cc_certificados cert ON gg.userid = cert.userid AND gi.courseid = cert.courseid
        WHERE gg.finalgrade IS NOT NULL
        AND cert.id IS NULL
        ORDER BY gg.timemodified DESC
    ");

    $pendingUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatear datos
    foreach ($pendingUsers as &$user) {
        $user['grade'] = $user['grade'] ? round($user['grade'], 2) : null;
        $user['grade_date_formatted'] = $user['grade_date'] ? date('Y-m-d H:i:s', $user['grade_date']) : null;
    }

    Response::success([
        'pending_users' => $pendingUsers,
        'total' => count($pendingUsers)
    ]);
}

/**
 * POST /api/admin/certificates/approve - Aprobar usuarios y generar certificados
 */
function handleApproveCertificates(): void
{
    Response::validateMethod('POST');

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['users']) || !is_array($input['users']) || empty($input['users'])) {
        Response::error('Se requiere un array de usuarios', 400, 'INVALID_INPUT');
    }

    $pdo = getDatabaseConnection();
    $templateService = new \ACG\Certificados\Services\TemplateService($pdo);

    $approved = 0;
    $certificatesCreated = 0;
    $pdfsGenerated = 0;
    $errors = [];

    foreach ($input['users'] as $userItem) {
        if (!isset($userItem['userid']) || !isset($userItem['course_id'])) {
            continue;
        }

        $userid = (int)$userItem['userid'];
        $courseId = (int)$userItem['course_id'];

        // Verificar que el usuario completó el curso
        $stmt = $pdo->prepare("
            SELECT
                u.firstname,
                u.lastname,
                u.email,
                u.idnumber,
                c.fullname as course_name,
                c.shortname as course_shortname,
                cc.timecompleted as completion_date,
                gg.finalgrade as grade
            FROM mdl_course_completions cc
            INNER JOIN mdl_user u ON cc.userid = u.id
            INNER JOIN mdl_course c ON cc.course = c.id
            LEFT JOIN mdl_grade_items gi ON gi.courseid = c.id AND gi.itemtype = 'course'
            LEFT JOIN mdl_grade_grades gg ON gg.itemid = gi.id AND gg.userid = u.id
            WHERE cc.userid = ? AND cc.course = ? AND cc.timecompleted IS NOT NULL
        ");
        $stmt->execute([$userid, $courseId]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$userData) {
            continue;
        }

        // Verificar que no existe ya un certificado
        $stmt = $pdo->prepare("
            SELECT id FROM cc_certificados WHERE userid = ? AND courseid = ?
        ");
        $stmt->execute([$userid, $courseId]);
        if ($stmt->fetch()) {
            continue; // Ya tiene certificado
        }

        // Generar número de certificado único
        $numeroCertificado = generateCertificateNumber();

        // Generar hash de validación
        $hashValidacion = hash('sha256', $numeroCertificado . $userid . $courseId . time());

        // Intensidad por defecto (40 horas) - TODO: obtener de configuración del curso en Moodle
        $intensidad = 40;

        // Crear registro del certificado con estado 'generado' (listo para notificar)
        $fechaEmision = time();
        $stmt = $pdo->prepare("
            INSERT INTO cc_certificados
            (userid, courseid, numero_certificado, hash_validacion, fecha_emision, intensidad, calificacion, estado, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'generado', ?, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
        ");
        $grade = $userData['grade'] ? round($userData['grade'], 2) : null;
        $createdBy = getAuthenticatedUserId() ?? $userid;
        $stmt->execute([$userid, $courseId, $numeroCertificado, $hashValidacion, $fechaEmision, $intensidad, $grade, $createdBy]);

        // Obtener el ID del certificado recién insertado
        $certificateId = (int)$pdo->lastInsertId();

        $certificatesCreated++;
        $approved++;

        // Generar el PDF del certificado inmediatamente
        try {
            $certificateData = [
                'numero_certificado' => $numeroCertificado,
                'userid' => $userid,
                'courseid' => $courseId,
                'firstname' => $userData['firstname'],
                'lastname' => $userData['lastname'],
                'idnumber' => $userData['idnumber'],
                'course_name' => $userData['course_name'],
                'intensidad' => $intensidad,
                'fecha_emision' => $fechaEmision
            ];

            // Medir tiempo de generación
            $startTime = microtime(true);

            $pdfPath = $templateService->generateCertificateForUser($certificateData);

            $endTime = microtime(true);
            $tiempoProcesamiento = (int)(($endTime - $startTime) * 1000); // en ms

            // Registrar la primera generación en el log
            logCertificateGeneration(
                $pdo,
                $certificateId,
                $userid,
                $courseId,
                $pdfPath,
                null, // pdf_size se calcula automáticamente
                $tiempoProcesamiento,
                'exitoso'
            );

            $pdfsGenerated++;
        } catch (Exception $e) {
            // Registrar el fallo en el log
            logCertificateGeneration(
                $pdo,
                $certificateId,
                $userid,
                $courseId,
                '',
                null,
                null,
                'fallido',
                $e->getMessage()
            );

            $errors[] = "Error generando PDF para {$userData['firstname']} {$userData['lastname']}: " . $e->getMessage();
            error_log("Error generating PDF for certificate {$numeroCertificado}: " . $e->getMessage());
        }
    }

    $response = [
        'approved' => $approved,
        'certificates_created' => $certificatesCreated,
        'pdfs_generated' => $pdfsGenerated
    ];

    if (!empty($errors)) {
        $response['warnings'] = $errors;
    }

    Response::success($response);
}

/**
 * GET /admin/certificates/generated - Listar todos los certificados generados
 *
 * Parámetros de query:
 * - search: Buscar por nombre, documento o curso
 * - sort: Campo de ordenamiento (numero_certificado, fecha_emision)
 * - order: Dirección (asc, desc)
 * - page: Número de página
 * - limit: Elementos por página
 */
function handleGeneratedCertificates(): void
{
    Response::validateMethod('GET');

    $pdo = getDatabaseConnection();

    // Parámetros de filtrado y paginación
    $search = $_GET['search'] ?? '';
    $sortField = $_GET['sort'] ?? 'fecha_emision';
    $sortOrder = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    // Validar campo de ordenamiento
    $allowedSortFields = ['numero_certificado', 'fecha_emision', 'created_at'];
    if (!in_array($sortField, $allowedSortFields)) {
        $sortField = 'fecha_emision';
    }

    // Construir query base
    $baseQuery = "
        FROM cc_certificados c
        INNER JOIN mdl_user u ON c.userid = u.id
        INNER JOIN mdl_course co ON c.courseid = co.id
        WHERE c.estado IN ('generado', 'notificado')
    ";

    $params = [];

    // Agregar filtro de búsqueda
    if (!empty($search)) {
        $searchPattern = '%' . $search . '%';
        $baseQuery .= " AND (
            CONCAT(u.firstname, ' ', u.lastname) LIKE :search1
            OR u.idnumber LIKE :search2
            OR co.fullname LIKE :search3
            OR co.shortname LIKE :search4
            OR c.numero_certificado LIKE :search5
        )";
        $params['search1'] = $searchPattern;
        $params['search2'] = $searchPattern;
        $params['search3'] = $searchPattern;
        $params['search4'] = $searchPattern;
        $params['search5'] = $searchPattern;
    }

    // Contar total
    $countStmt = $pdo->prepare("SELECT COUNT(*) as total " . $baseQuery);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Obtener certificados
    $query = "
        SELECT
            c.id,
            c.numero_certificado,
            c.userid,
            c.courseid,
            c.fecha_emision,
            c.intensidad,
            c.calificacion,
            c.estado,
            c.created_at,
            c.updated_at,
            u.firstname,
            u.lastname,
            u.idnumber,
            u.email,
            co.fullname as course_name,
            co.shortname as course_shortname
        " . $baseQuery . "
        ORDER BY {$sortField} {$sortOrder}
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatear fechas y verificar existencia de PDFs
    $templateService = new TemplateService($pdo);
    foreach ($certificates as &$cert) {
        $cert['fecha_emision_formatted'] = date('d/m/Y', $cert['fecha_emision']);
        $cert['created_at_formatted'] = date('d/m/Y H:i', $cert['created_at']);
        $cert['participante'] = $cert['firstname'] . ' ' . $cert['lastname'];

        // Verificar si existe el PDF
        $pdfPath = $templateService->getCertificatePath($cert['numero_certificado']);
        $cert['pdf_exists'] = $pdfPath !== null && file_exists($pdfPath);
    }

    Response::success([
        'certificates' => $certificates,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

/**
 * POST /admin/certificates/regenerate - Regenerar certificados
 *
 * Body: { certificate_ids: [1, 2, 3] }
 *
 * Regenera los PDFs de los certificados seleccionados usando la plantilla actual.
 * NO modifica fecha_emision (es la fecha de la última calificación).
 * Registra cada generación en cc_generaciones_log.
 */
function handleRegenerateCertificates(): void
{
    Response::validateMethod('POST');

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['certificate_ids']) || !is_array($input['certificate_ids']) || empty($input['certificate_ids'])) {
        Response::error('Se requiere un array de IDs de certificados', 400, 'INVALID_INPUT');
    }

    $pdo = getDatabaseConnection();
    $templateService = new TemplateService($pdo);

    $regenerated = 0;
    $failed = 0;
    $details = [];

    foreach ($input['certificate_ids'] as $certId) {
        $certId = (int)$certId;

        try {
            // Obtener datos del certificado
            $stmt = $pdo->prepare("
                SELECT
                    c.*,
                    u.firstname,
                    u.lastname,
                    u.email,
                    u.idnumber,
                    co.fullname as course_name,
                    co.shortname as course_shortname
                FROM cc_certificados c
                INNER JOIN mdl_user u ON c.userid = u.id
                INNER JOIN mdl_course co ON c.courseid = co.id
                WHERE c.id = :id
            ");
            $stmt->execute(['id' => $certId]);
            $certificate = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$certificate) {
                $failed++;
                $details[] = [
                    'id' => $certId,
                    'numero_certificado' => null,
                    'success' => false,
                    'error' => "Certificado no encontrado"
                ];
                continue;
            }

            // Eliminar PDF existente si existe
            $existingPdf = $templateService->getCertificatePath($certificate['numero_certificado']);
            if ($existingPdf && file_exists($existingPdf)) {
                unlink($existingPdf);
            }

            // NO actualizar fecha_emision - es la fecha de la última calificación
            // Solo actualizar updated_at
            $stmt = $pdo->prepare("
                UPDATE cc_certificados
                SET updated_at = UNIX_TIMESTAMP()
                WHERE id = :id
            ");
            $stmt->execute(['id' => $certId]);

            // Medir tiempo de generación
            $startTime = microtime(true);

            // Generar nuevo PDF (usando la fecha_emision original)
            $pdfPath = $templateService->generateCertificateForUser($certificate);

            $endTime = microtime(true);
            $tiempoProcesamiento = (int)(($endTime - $startTime) * 1000); // en ms

            // Registrar en log de generaciones
            logCertificateGeneration(
                $pdo,
                $certId,
                (int)$certificate['userid'],
                (int)$certificate['courseid'],
                $pdfPath,
                null, // pdf_size se calcula automáticamente
                $tiempoProcesamiento,
                'exitoso'
            );

            $regenerated++;
            $details[] = [
                'id' => $certId,
                'numero_certificado' => $certificate['numero_certificado'],
                'success' => true
            ];

        } catch (Exception $e) {
            // Registrar el fallo en el log
            if (isset($certificate) && $certificate) {
                logCertificateGeneration(
                    $pdo,
                    $certId,
                    (int)$certificate['userid'],
                    (int)$certificate['courseid'],
                    '',
                    null,
                    null,
                    'fallido',
                    $e->getMessage()
                );
            }

            $failed++;
            $details[] = [
                'id' => $certId,
                'numero_certificado' => $certificate['numero_certificado'] ?? null,
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    Response::success([
        'regenerated' => $regenerated,
        'failed' => $failed,
        'details' => $details
    ]);
}

/**
 * Registra una generación de certificado en el log
 *
 * Usa la tabla cc_generaciones_log existente con su estructura completa.
 *
 * @param PDO $pdo Conexión a BD
 * @param int $certificadoId ID del certificado
 * @param int $userid ID del usuario para quien se genera
 * @param int $courseid ID del curso
 * @param string $pdfPath Ruta del PDF generado
 * @param int|null $pdfSize Tamaño del PDF en bytes
 * @param int|null $tiempoProcesamiento Tiempo de procesamiento en ms
 * @param string $resultado 'exitoso' o 'fallido'
 * @param string|null $mensajeError Mensaje de error si falló
 */
function logCertificateGeneration(
    PDO $pdo,
    int $certificadoId,
    int $userid,
    int $courseid,
    string $pdfPath,
    ?int $pdfSize = null,
    ?int $tiempoProcesamiento = null,
    string $resultado = 'exitoso',
    ?string $mensajeError = null
): void {
    // Obtener tamaño del PDF si no se proporcionó
    if ($pdfSize === null && file_exists($pdfPath)) {
        $pdfSize = filesize($pdfPath);
    }

    // Obtener el usuario autenticado (admin/gestor que realiza la acción)
    $authenticatedUserId = getAuthenticatedUserId() ?? $userid;

    $stmt = $pdo->prepare("
        INSERT INTO cc_generaciones_log
        (certificado_id, userid, courseid, resultado, mensaje_error, tiempo_procesamiento,
         pdf_generado, pdf_size, generado_por, generado_en, ip_address, user_agent)
        VALUES (:cert_id, :userid, :courseid, :resultado, :mensaje_error, :tiempo,
                :pdf_generado, :pdf_size, :generado_por, UNIX_TIMESTAMP(), :ip, :user_agent)
    ");
    $stmt->execute([
        'cert_id' => $certificadoId,
        'userid' => $userid,
        'courseid' => $courseid,
        'resultado' => $resultado,
        'mensaje_error' => $mensajeError,
        'tiempo' => $tiempoProcesamiento,
        'pdf_generado' => $resultado === 'exitoso' ? 1 : 0,
        'pdf_size' => $pdfSize,
        'generado_por' => $authenticatedUserId,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
    ]);
}

/**
 * GET /admin/certificates/{id}/generations - Obtener historial de generaciones
 *
 * Devuelve un array con todas las generaciones registradas de un certificado.
 */
function handleGetCertificateGenerations(int $certificateId): void
{
    Response::validateMethod('GET');

    $pdo = getDatabaseConnection();

    // Verificar que el certificado existe
    $stmt = $pdo->prepare("SELECT numero_certificado FROM cc_certificados WHERE id = ?");
    $stmt->execute([$certificateId]);
    $certificate = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$certificate) {
        Response::error('Certificado no encontrado', 404, 'NOT_FOUND');
    }

    // Obtener historial de generaciones
    $stmt = $pdo->prepare("
        SELECT
            g.id,
            g.certificado_id,
            g.userid,
            g.courseid,
            g.plantilla_id,
            g.resultado,
            g.mensaje_error,
            g.tiempo_procesamiento,
            g.pdf_generado,
            g.pdf_size,
            g.generado_por,
            g.generado_en,
            FROM_UNIXTIME(g.generado_en, '%Y-%m-%d %H:%i:%s') as generado_en_formatted,
            g.ip_address,
            u.firstname as generado_por_nombre,
            u.lastname as generado_por_apellido
        FROM cc_generaciones_log g
        LEFT JOIN mdl_user u ON g.generado_por = u.id
        WHERE g.certificado_id = ?
        ORDER BY g.generado_en DESC
    ");
    $stmt->execute([$certificateId]);
    $generations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    Response::success([
        'certificate_id' => $certificateId,
        'numero_certificado' => $certificate['numero_certificado'],
        'generations' => $generations,
        'total' => count($generations)
    ]);
}

/**
 * POST /admin/certificates/download-zip - Descargar múltiples certificados como ZIP
 *
 * Body: { certificate_ids: [1, 2, 3] }
 */
function handleDownloadCertificatesZip(): void
{
    Response::validateMethod('POST');

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['certificate_ids']) || !is_array($input['certificate_ids']) || empty($input['certificate_ids'])) {
        Response::error('Se requiere un array de IDs de certificados', 400, 'INVALID_INPUT');
    }

    $pdo = getDatabaseConnection();
    $templateService = new TemplateService($pdo);

    // Crear archivo ZIP temporal
    $zipPath = TEMP_PATH . '/certificates_' . date('YmdHis') . '_' . uniqid() . '.zip';
    $zip = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        Response::error('Error creando archivo ZIP', 500, 'ZIP_ERROR');
    }

    $added = 0;
    $errors = [];

    foreach ($input['certificate_ids'] as $certId) {
        $certId = (int)$certId;

        try {
            // Obtener datos del certificado
            $stmt = $pdo->prepare("
                SELECT
                    c.*,
                    u.firstname,
                    u.lastname,
                    u.idnumber,
                    co.fullname as course_name,
                    co.shortname as course_shortname
                FROM cc_certificados c
                INNER JOIN mdl_user u ON c.userid = u.id
                INNER JOIN mdl_course co ON c.courseid = co.id
                WHERE c.id = :id
            ");
            $stmt->execute(['id' => $certId]);
            $certificate = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$certificate) {
                $errors[] = "Certificado ID {$certId} no encontrado";
                continue;
            }

            // Verificar si existe el PDF, si no, generarlo
            $pdfPath = $templateService->getCertificatePath($certificate['numero_certificado']);
            if (!$pdfPath || !file_exists($pdfPath)) {
                $pdfPath = $templateService->generateCertificateForUser($certificate);
            }

            // Nombre del archivo en el ZIP
            $zipFilename = sprintf(
                '%s_%s_%s.pdf',
                $certificate['numero_certificado'],
                preg_replace('/[^a-zA-Z0-9]/', '_', $certificate['lastname']),
                preg_replace('/[^a-zA-Z0-9]/', '_', $certificate['firstname'])
            );

            $zip->addFile($pdfPath, $zipFilename);
            $added++;

        } catch (Exception $e) {
            $errors[] = "Error con certificado ID {$certId}: " . $e->getMessage();
        }
    }

    $zip->close();

    if ($added === 0) {
        // No se agregó ningún archivo, eliminar ZIP y reportar error
        if (file_exists($zipPath)) {
            unlink($zipPath);
        }
        Response::error('No se pudo agregar ningún certificado al ZIP', 500, 'ZIP_EMPTY', ['errors' => $errors]);
    }

    // Enviar ZIP al navegador
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="certificados_' . date('Y-m-d') . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: private, max-age=0, must-revalidate');

    readfile($zipPath);

    // Limpiar archivo temporal
    unlink($zipPath);
    exit;
}

/**
 * Genera un número de certificado único
 * Formato: CV-XXXX donde XXXX es secuencial
 */
function generateCertificateNumber(): string
{
    $pdo = getDatabaseConnection();

    // Obtener el último número de certificado
    $stmt = $pdo->query("
        SELECT numero_certificado FROM cc_certificados
        WHERE numero_certificado LIKE 'CV-%'
        ORDER BY CAST(SUBSTRING(numero_certificado, 4) AS UNSIGNED) DESC
        LIMIT 1
    ");
    $lastCert = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($lastCert) {
        $lastNumber = (int)substr($lastCert['numero_certificado'], 3);
        $newNumber = $lastNumber + 1;
    } else {
        // Si no hay certificados previos, empezar en 3490 (después del último CV-3489)
        $newNumber = 3490;
    }

    return 'CV-' . $newNumber;
}

// ============================================================================
// HELPERS
// ============================================================================

/**
 * Obtiene el ID del usuario autenticado desde los headers HTTP.
 *
 * El frontend envía el header X-User-Id con el ID del usuario que realiza la acción.
 * Esto es diferente del participante del certificado.
 *
 * @return int|null ID del usuario autenticado o null si no está disponible
 */
function getAuthenticatedUserId(): ?int
{
    // Intentar obtener de diferentes formatos de header
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    }

    // Buscar X-User-Id (el header puede llegar con diferentes formatos)
    $userId = null;

    // Formato directo
    if (isset($headers['X-User-Id'])) {
        $userId = (int) $headers['X-User-Id'];
    }
    // Formato Apache (convierte a lowercase con guiones)
    elseif (isset($_SERVER['HTTP_X_USER_ID'])) {
        $userId = (int) $_SERVER['HTTP_X_USER_ID'];
    }

    return $userId ?: null;
}

/**
 * Obtiene conexión a la base de datos
 */
function getDatabaseConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            Response::error(
                'Error de conexión a base de datos',
                500,
                'DB_CONNECTION_ERROR',
                DEBUG_MODE ? ['error' => $e->getMessage()] : []
            );
        }
    }

    return $pdo;
}

/**
 * Prueba la conexión a la base de datos
 */
function testDatabaseConnection(): array
{
    try {
        $pdo = getDatabaseConnection();
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();

        return [
            'connected' => true,
            'version' => $version,
            'host' => DB_HOST,
            'database' => DB_NAME
        ];
    } catch (\Exception $e) {
        return [
            'connected' => false,
            'error' => DEBUG_MODE ? $e->getMessage() : 'Error de conexión'
        ];
    }
}

/**
 * Registra una descarga de certificado
 */
function logDownload(array $certificate, string $ipAddress): void
{
    try {
        $pdo = getDatabaseConnection();

        $stmt = $pdo->prepare("
            INSERT INTO cc_descargas_log
            (certificado_id, userid, fecha_descarga, ip_address, user_agent)
            VALUES
            (:certificado_id, :userid, :fecha_descarga, :ip_address, :user_agent)
        ");

        $stmt->execute([
            'certificado_id' => $certificate['id'],
            'userid' => $certificate['userid'],
            'fecha_descarga' => time(),
            'ip_address' => $ipAddress,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
    } catch (\Exception $e) {
        // No interrumpir descarga si falla el log
        error_log("Error logging download: " . $e->getMessage());
    }
}

/**
 * GET /api/admin/notifications/pending - Certificados pendientes de notificar
 *
 * Lista certificados con estado 'generado' (PDF listo pero no notificado al usuario)
 */
function handlePendingNotifications(): void
{
    Response::validateMethod('GET');

    $pdo = getDatabaseConnection();

    // Certificados generados pendientes de notificación
    $stmt = $pdo->query("
        SELECT
            c.id as certificate_id,
            c.numero_certificado,
            c.userid,
            u.firstname,
            u.lastname,
            u.email,
            c.courseid as course_id,
            co.fullname as course_name,
            co.shortname as course_shortname,
            c.calificacion as grade,
            FROM_UNIXTIME(c.fecha_emision, '%Y-%m-%d') as fecha_emision,
            FROM_UNIXTIME(c.created_at, '%Y-%m-%d %H:%i:%s') as created_at
        FROM cc_certificados c
        INNER JOIN mdl_user u ON c.userid = u.id
        INNER JOIN mdl_course co ON c.courseid = co.id
        WHERE c.estado = 'generado'
        ORDER BY c.created_at DESC
    ");

    $certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    Response::success([
        'certificates' => $certificates,
        'total' => count($certificates)
    ]);
}

/**
 * POST /api/admin/notifications/send - Enviar notificaciones por email
 *
 * Recibe un array de certificate_ids y envía emails a cada participante.
 * Por ahora es un MOCK que simula el envío (integración con Google Apps Script pendiente).
 */
function handleSendNotifications(): void
{
    Response::validateMethod('POST');

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['certificate_ids']) || !is_array($input['certificate_ids']) || empty($input['certificate_ids'])) {
        Response::error('Se requiere un array de certificate_ids', 400, 'INVALID_INPUT');
    }

    $pdo = getDatabaseConnection();
    $sent = 0;
    $failed = 0;
    $errors = [];

    foreach ($input['certificate_ids'] as $certId) {
        $certId = (int)$certId;

        // Obtener datos del certificado
        $stmt = $pdo->prepare("
            SELECT
                c.id,
                c.numero_certificado,
                c.userid,
                c.estado,
                u.firstname,
                u.lastname,
                u.email,
                co.fullname as course_name
            FROM cc_certificados c
            INNER JOIN mdl_user u ON c.userid = u.id
            INNER JOIN mdl_course co ON c.courseid = co.id
            WHERE c.id = ?
        ");
        $stmt->execute([$certId]);
        $cert = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cert) {
            $failed++;
            $errors[] = "Certificado ID $certId no encontrado";
            continue;
        }

        if ($cert['estado'] !== 'generado') {
            $failed++;
            $errors[] = "Certificado {$cert['numero_certificado']} no está en estado 'generado'";
            continue;
        }

        // ================================================================
        // MOCK: Simular envío de email
        // TODO: Integrar con Google Apps Script para envío real
        // ================================================================
        $emailSent = mockSendEmail($cert);

        if ($emailSent) {
            // Actualizar estado del certificado a 'notificado'
            $stmt = $pdo->prepare("
                UPDATE cc_certificados
                SET estado = 'notificado',
                    fecha_notificacion = UNIX_TIMESTAMP(),
                    updated_at = UNIX_TIMESTAMP()
                WHERE id = ?
            ");
            $stmt->execute([$certId]);

            // Registrar en log de notificaciones
            logNotification($pdo, $certId, $cert['email'], 'sent');

            $sent++;
        } else {
            // Registrar fallo en log
            logNotification($pdo, $certId, $cert['email'], 'failed', 'Error al enviar email (mock)');

            $failed++;
            $errors[] = "Error enviando a {$cert['email']}";
        }
    }

    Response::success([
        'sent' => $sent,
        'failed' => $failed,
        'errors' => $errors
    ]);
}

/**
 * MOCK: Simula el envío de un email
 *
 * En producción, esto llamará a Google Apps Script para enviar el email real.
 * Por ahora, simula éxito en el 100% de los casos.
 *
 * @param array $certificate Datos del certificado
 * @return bool true si el email se "envió" correctamente
 */
function mockSendEmail(array $certificate): bool
{
    // Simular un pequeño delay como si estuviéramos enviando
    usleep(100000); // 100ms

    // Log del mock para debugging
    error_log(sprintf(
        "[MOCK EMAIL] Enviando a: %s <%s> - Certificado: %s - Curso: %s",
        $certificate['firstname'] . ' ' . $certificate['lastname'],
        $certificate['email'],
        $certificate['numero_certificado'],
        $certificate['course_name']
    ));

    // TODO: Cuando se integre con Google Apps Script, esto será:
    // return $gasService->sendCertificateNotification($certificate);

    // Por ahora, siempre retorna éxito
    return true;
}

/**
 * Registra una notificación en el log
 */
function logNotification(PDO $pdo, int $certificateId, string $email, string $status, string $errorMessage = null): void
{
    try {
        // Verificar si existe la tabla de log
        $stmt = $pdo->query("SHOW TABLES LIKE 'cc_notifications_log'");
        if (!$stmt->fetch()) {
            // Crear tabla si no existe
            $pdo->exec("
                CREATE TABLE cc_notifications_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    certificate_id INT NOT NULL,
                    recipient_email VARCHAR(255) NOT NULL,
                    status ENUM('sent', 'failed', 'pending') NOT NULL DEFAULT 'pending',
                    error_message TEXT,
                    sent_at INT,
                    created_at INT NOT NULL,
                    INDEX idx_certificate (certificate_id),
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        $stmt = $pdo->prepare("
            INSERT INTO cc_notifications_log
            (certificate_id, recipient_email, status, error_message, sent_at, created_at)
            VALUES (?, ?, ?, ?, ?, UNIX_TIMESTAMP())
        ");

        $sentAt = ($status === 'sent') ? time() : null;
        $stmt->execute([$certificateId, $email, $status, $errorMessage, $sentAt]);

    } catch (\Exception $e) {
        error_log("Error logging notification: " . $e->getMessage());
    }
}

/**
 * GET /api/admin/badges - Conteos para badges del sidebar
 *
 * Retorna:
 * - pending_approved: Usuarios con calificación >= 80% sin certificado generado
 * - pending_notifications: Certificados generados pendientes de notificar
 */
function handleAdminBadges(): void
{
    Response::validateMethod('GET');

    $pdo = getDatabaseConnection();

    // Usuarios con calificación >= 80% que no tienen certificado
    $stmt = $pdo->query("
        SELECT COUNT(*) as count
        FROM mdl_grade_grades gg
        INNER JOIN mdl_grade_items gi ON gg.itemid = gi.id AND gi.itemtype = 'course'
        LEFT JOIN cc_certificados cert ON gg.userid = cert.userid AND gi.courseid = cert.courseid
        WHERE gg.finalgrade IS NOT NULL
        AND gg.finalgrade >= 80
        AND cert.id IS NULL
    ");
    $pendingApproved = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Certificados generados pendientes de notificación
    $stmt = $pdo->query("
        SELECT COUNT(*) as count
        FROM cc_certificados
        WHERE estado = 'generado'
    ");
    $pendingNotifications = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];

    Response::success([
        'pending_approved' => $pendingApproved,
        'pending_notifications' => $pendingNotifications
    ]);
}

/**
 * GET /api/admin/report/export - Exportar reporte de certificados en Excel (XLSX)
 *
 * Incluye todos los certificados con sus estados:
 * - Pendientes (usuarios con calificación >= 80% sin certificado)
 * - Generados (certificado creado, pendiente de notificar)
 * - Notificados (certificado enviado al participante)
 */
function handleExportReport(): void
{
    Response::validateMethod('GET');

    $pdo = getDatabaseConnection();

    // Obtener todos los datos para el reporte
    $reportData = [];

    // 1. Certificados existentes (generados y notificados)
    $stmt = $pdo->query("
        SELECT
            c.numero_certificado,
            u.firstname,
            u.lastname,
            u.email,
            u.idnumber as documento,
            co.fullname as curso,
            co.shortname as curso_codigo,
            c.calificacion,
            c.intensidad,
            c.estado,
            FROM_UNIXTIME(c.fecha_emision, '%Y-%m-%d') as fecha_emision,
            FROM_UNIXTIME(c.fecha_notificacion, '%Y-%m-%d') as fecha_notificacion
        FROM cc_certificados c
        INNER JOIN mdl_user u ON c.userid = u.id
        INNER JOIN mdl_course co ON c.courseid = co.id
        ORDER BY c.estado DESC, c.fecha_emision DESC
    ");
    $certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($certificates as $cert) {
        $reportData[] = [
            'numero_certificado' => $cert['numero_certificado'],
            'nombres' => $cert['firstname'],
            'apellidos' => $cert['lastname'],
            'email' => $cert['email'],
            'documento' => $cert['documento'],
            'curso' => $cert['curso'],
            'curso_codigo' => $cert['curso_codigo'],
            'calificacion' => $cert['calificacion'],
            'intensidad' => $cert['intensidad'],
            'estado' => ucfirst($cert['estado']),
            'fecha_emision' => $cert['fecha_emision'],
            'fecha_notificacion' => $cert['fecha_notificacion'] ?: ''
        ];
    }

    // 2. Usuarios pendientes (calificación >= 80% sin certificado)
    $stmt = $pdo->query("
        SELECT
            u.firstname,
            u.lastname,
            u.email,
            u.idnumber as documento,
            co.fullname as curso,
            co.shortname as curso_codigo,
            gg.finalgrade as calificacion,
            FROM_UNIXTIME(gg.timemodified, '%Y-%m-%d') as fecha_calificacion
        FROM mdl_grade_grades gg
        INNER JOIN mdl_grade_items gi ON gg.itemid = gi.id AND gi.itemtype = 'course'
        INNER JOIN mdl_user u ON gg.userid = u.id
        INNER JOIN mdl_course co ON gi.courseid = co.id
        LEFT JOIN cc_certificados cert ON gg.userid = cert.userid AND gi.courseid = cert.courseid
        WHERE gg.finalgrade IS NOT NULL
        AND gg.finalgrade >= 80
        AND cert.id IS NULL
        ORDER BY gg.timemodified DESC
    ");
    $pendingUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pendingUsers as $pending) {
        $reportData[] = [
            'numero_certificado' => '',
            'nombres' => $pending['firstname'],
            'apellidos' => $pending['lastname'],
            'email' => $pending['email'],
            'documento' => $pending['documento'],
            'curso' => $pending['curso'],
            'curso_codigo' => $pending['curso_codigo'],
            'calificacion' => round($pending['calificacion'], 2),
            'intensidad' => '',
            'estado' => 'Pendiente',
            'fecha_emision' => '',
            'fecha_notificacion' => ''
        ];
    }

    // Generar Excel usando PhpSpreadsheet si está disponible, sino CSV
    $usePhpSpreadsheet = class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet');

    if ($usePhpSpreadsheet) {
        generateExcelSpreadsheet($reportData);
    } else {
        generateExcelCsv($reportData);
    }
}

/**
 * Genera archivo Excel usando PhpSpreadsheet
 */
function generateExcelSpreadsheet(array $data): void
{
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Certificados');

    // Headers
    $headers = [
        'A1' => 'No. Certificado',
        'B1' => 'Nombres',
        'C1' => 'Apellidos',
        'D1' => 'Email',
        'E1' => 'Documento',
        'F1' => 'Curso',
        'G1' => 'Código Curso',
        'H1' => 'Calificación',
        'I1' => 'Intensidad (h)',
        'J1' => 'Estado',
        'K1' => 'Fecha Emisión',
        'L1' => 'Fecha Notificación'
    ];

    foreach ($headers as $cell => $value) {
        $sheet->setCellValue($cell, $value);
    }

    // Estilo de headers
    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => '0066CC']
        ],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
    ];
    $sheet->getStyle('A1:L1')->applyFromArray($headerStyle);

    // Datos
    $row = 2;
    foreach ($data as $item) {
        $sheet->setCellValue("A{$row}", $item['numero_certificado']);
        $sheet->setCellValue("B{$row}", $item['nombres']);
        $sheet->setCellValue("C{$row}", $item['apellidos']);
        $sheet->setCellValue("D{$row}", $item['email']);
        $sheet->setCellValue("E{$row}", $item['documento']);
        $sheet->setCellValue("F{$row}", $item['curso']);
        $sheet->setCellValue("G{$row}", $item['curso_codigo']);
        $sheet->setCellValue("H{$row}", $item['calificacion']);
        $sheet->setCellValue("I{$row}", $item['intensidad']);
        $sheet->setCellValue("J{$row}", $item['estado']);
        $sheet->setCellValue("K{$row}", $item['fecha_emision']);
        $sheet->setCellValue("L{$row}", $item['fecha_notificacion']);

        // Color por estado
        $stateColor = match($item['estado']) {
            'Pendiente' => 'FFF3CD',
            'Generado' => 'D1ECF1',
            'Notificado' => 'D4EDDA',
            default => 'FFFFFF'
        };
        $sheet->getStyle("A{$row}:L{$row}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB($stateColor);

        $row++;
    }

    // Auto-size columns
    foreach (range('A', 'L') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Output
    $filename = 'reporte-certificados-' . date('Y-m-d') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

/**
 * Genera archivo CSV compatible con Excel (fallback si no hay PhpSpreadsheet)
 */
function generateExcelCsv(array $data): void
{
    $filename = 'reporte-certificados-' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    // BOM para UTF-8 en Excel
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    // Headers - PHP 8.4 requiere el parámetro escape explícito
    fputcsv($output, [
        'No. Certificado',
        'Nombres',
        'Apellidos',
        'Email',
        'Documento',
        'Curso',
        'Código Curso',
        'Calificación',
        'Intensidad (h)',
        'Estado',
        'Fecha Emisión',
        'Fecha Notificación'
    ], ';', '"', '\\'); // separador, enclosure, escape

    // Datos
    foreach ($data as $item) {
        fputcsv($output, [
            $item['numero_certificado'],
            $item['nombres'],
            $item['apellidos'],
            $item['email'],
            $item['documento'],
            $item['curso'],
            $item['curso_codigo'],
            $item['calificacion'],
            $item['intensidad'],
            $item['estado'],
            $item['fecha_emision'],
            $item['fecha_notificacion']
        ], ';', '"', '\\');
    }

    fclose($output);
    exit;
}

/**
 * GET /api/admin/settings - Obtener configuración del sistema
 */
function handleGetSettings(): void
{
    Response::validateMethod('GET');

    $pdo = getDatabaseConnection();

    // Verificar si existe la tabla de configuración
    ensureSettingsTableExists($pdo);

    // Obtener configuraciones
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM cc_settings");
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Valores por defecto
    $defaults = getDefaultSettings();

    // Combinar con valores guardados
    $settings = [];
    foreach ($defaults as $key => $default) {
        $settings[$key] = $rows[$key] ?? $default;

        // Convertir tipos según corresponda
        if (in_array($key, ['default_intensity', 'default_template_id'])) {
            $settings[$key] = (int)$settings[$key];
        }
        if ($key === 'gas_enabled') {
            $settings[$key] = (bool)$settings[$key];
        }
    }

    Response::success($settings);
}

/**
 * PUT /api/admin/settings - Actualizar configuración del sistema
 */
function handleUpdateSettings(): void
{
    Response::validateMethod('PUT');

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !is_array($input)) {
        Response::error('Datos de configuración inválidos', 400, 'INVALID_INPUT');
    }

    $pdo = getDatabaseConnection();

    // Verificar si existe la tabla
    ensureSettingsTableExists($pdo);

    // Claves permitidas
    $allowedKeys = array_keys(getDefaultSettings());

    // Actualizar cada configuración
    $updated = 0;
    foreach ($input as $key => $value) {
        if (!in_array($key, $allowedKeys)) {
            continue; // Ignorar claves no permitidas
        }

        // Validar valores específicos
        if ($key === 'default_intensity' && (!is_numeric($value) || $value < 1)) {
            continue;
        }
        if ($key === 'cron_execution_time' && !preg_match('/^\d{2}:\d{2}$/', $value)) {
            continue;
        }
        if ($key === 'notification_email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        // Convertir booleanos a string
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        // Insertar o actualizar
        $stmt = $pdo->prepare("
            INSERT INTO cc_settings (setting_key, setting_value, updated_at)
            VALUES (:key, :value, UNIX_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = UNIX_TIMESTAMP()
        ");
        $stmt->execute(['key' => $key, 'value' => (string)$value]);
        $updated++;
    }

    // Obtener configuración actualizada
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM cc_settings");
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $defaults = getDefaultSettings();
    $settings = [];
    foreach ($defaults as $key => $default) {
        $settings[$key] = $rows[$key] ?? $default;
        if (in_array($key, ['default_intensity', 'default_template_id'])) {
            $settings[$key] = (int)$settings[$key];
        }
        if ($key === 'gas_enabled') {
            $settings[$key] = (bool)$settings[$key];
        }
    }

    Response::success([
        'updated' => true,
        'settings' => $settings
    ], "Se actualizaron {$updated} configuraciones");
}

/**
 * Retorna los valores por defecto de configuración
 */
function getDefaultSettings(): array
{
    return [
        'default_intensity' => '40',
        'default_template_id' => '1',
        'certificate_prefix' => 'CV-',
        'notification_email' => EMAIL_GESTOR ?? 'cursosvirtualesacg@gmail.com',
        'email_from_name' => EMAIL_FROM_NAME ?? 'Grupo Capacitación ACG',
        'cron_execution_time' => CRON_HORA_EJECUCION ?? '07:00',
        'gas_webhook_url' => GAS_WEBHOOK_URL ?? '',
        'gas_enabled' => '0',
        'validation_url' => 'https://certificados.acgcalidad.co/validar'
    ];
}

/**
 * Asegura que la tabla de configuración exista
 */
function ensureSettingsTableExists(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW TABLES LIKE 'cc_settings'");
    if (!$stmt->fetch()) {
        $pdo->exec("
            CREATE TABLE cc_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) NOT NULL UNIQUE,
                setting_value TEXT,
                updated_at INT,
                INDEX idx_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

// ============================================================================
// HANDLERS - PLANTILLAS
// ============================================================================

/**
 * GET /admin/templates - Lista todas las plantillas
 */
function handleGetTemplates(): void
{
    Response::validateMethod('GET');

    try {
        $pdo = getDatabaseConnection();
        $templateService = new TemplateService($pdo);

        $data = $templateService->getAllTemplates();

        Response::success([
            'base_template' => $data['base_template'],
            'course_templates' => $data['course_templates'],
            'courses_without_template' => $data['courses_without_template'],
            'second_page_fields' => $data['second_page_fields']
        ]);

    } catch (Exception $e) {
        Response::error(
            'Error obteniendo plantillas',
            500,
            'TEMPLATES_ERROR',
            DEBUG_MODE ? ['error' => $e->getMessage()] : []
        );
    }
}

/**
 * POST /admin/templates/base - Sube o actualiza la plantilla base
 */
function handleUploadBaseTemplate(): void
{
    Response::validateMethod('POST');

    // Verificar que se subió un archivo
    if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
        Response::error('No se proporcionó ningún archivo', 400, 'NO_FILE');
    }

    // Obtener nombre opcional
    $nombre = $_POST['nombre'] ?? null;

    // TODO: Obtener usuario autenticado de la sesión
    $uploadedBy = 2; // Por ahora, usar usuario de prueba

    try {
        $pdo = getDatabaseConnection();
        $templateService = new TemplateService($pdo);

        $result = $templateService->uploadBaseTemplate($_FILES['file'], $uploadedBy, $nombre);

        Response::success($result, 'Plantilla base actualizada correctamente');

    } catch (Exception $e) {
        Response::error(
            $e->getMessage(),
            400,
            'UPLOAD_ERROR'
        );
    }
}

/**
 * POST /admin/templates/course/{courseid} - Sube o actualiza plantilla de curso
 */
function handleUploadCourseTemplate(int $courseid): void
{
    Response::validateMethod('POST');

    // Verificar que se subió un archivo
    if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
        Response::error('No se proporcionó ningún archivo', 400, 'NO_FILE');
    }

    // Obtener nombre opcional
    $nombre = $_POST['nombre'] ?? null;

    // TODO: Obtener usuario autenticado de la sesión
    $uploadedBy = 2; // Por ahora, usar usuario de prueba

    try {
        $pdo = getDatabaseConnection();
        $templateService = new TemplateService($pdo);

        $result = $templateService->uploadCourseTemplate($courseid, $_FILES['file'], $uploadedBy, $nombre);

        Response::success($result, 'Plantilla del curso actualizada correctamente');

    } catch (Exception $e) {
        Response::error(
            $e->getMessage(),
            400,
            'UPLOAD_ERROR'
        );
    }
}

/**
 * DELETE /admin/templates/course/{courseid} - Elimina plantilla de curso
 */
function handleDeleteCourseTemplate(int $courseid): void
{
    Response::validateMethod('DELETE');

    // TODO: Obtener usuario autenticado de la sesión
    $deletedBy = 2; // Por ahora, usar usuario de prueba

    try {
        $pdo = getDatabaseConnection();
        $templateService = new TemplateService($pdo);

        $templateService->deleteCourseTemplate($courseid, $deletedBy);

        Response::success(null, 'Plantilla del curso eliminada correctamente');

    } catch (Exception $e) {
        Response::error(
            $e->getMessage(),
            404,
            'DELETE_ERROR'
        );
    }
}

/**
 * GET /admin/templates/{id}/download - Descarga archivo PPTX de una plantilla
 */
function handleDownloadTemplate(int $templateId): void
{
    Response::validateMethod('GET');

    try {
        $pdo = getDatabaseConnection();
        $templateService = new TemplateService($pdo);

        $template = $templateService->getTemplateForDownload($templateId);

        if (!$template) {
            Response::error('Plantilla no encontrada', 404, 'NOT_FOUND');
        }

        // Enviar archivo
        header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
        header('Content-Disposition: attachment; filename="' . $template['archivo'] . '"');
        header('Content-Length: ' . filesize($template['file_path']));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        readfile($template['file_path']);
        exit;

    } catch (Exception $e) {
        Response::error(
            'Error descargando plantilla',
            500,
            'DOWNLOAD_ERROR',
            DEBUG_MODE ? ['error' => $e->getMessage()] : []
        );
    }
}

/**
 * POST /admin/templates/preview - Genera PDF de preview con datos de ejemplo
 */
function handleTemplatePreview(): void
{
    Response::validateMethod('POST');

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['courseid'])) {
        Response::error('Se requiere courseid', 400, 'INVALID_INPUT');
    }

    $courseid = (int)$input['courseid'];

    try {
        $pdo = getDatabaseConnection();
        $templateService = new TemplateService($pdo);

        $pdfPath = $templateService->generatePreview($courseid);

        // Enviar PDF
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="preview-certificado.pdf"');
        header('Content-Length: ' . filesize($pdfPath));
        header('Cache-Control: private, max-age=0, must-revalidate');

        readfile($pdfPath);

        // Limpiar archivo temporal
        if (strpos($pdfPath, '/temp/') !== false) {
            unlink($pdfPath);
        }

        exit;

    } catch (Exception $e) {
        Response::error(
            $e->getMessage(),
            400,
            'PREVIEW_ERROR'
        );
    }
}

/**
 * PUT /admin/templates/{id}/fields - Guarda coordenadas de campos de una plantilla
 */
function handleSaveTemplateFields(int $templateId): void
{
    Response::validateMethod('PUT');

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['campos']) || !is_array($input['campos'])) {
        Response::error('Se requiere un objeto "campos" con las coordenadas', 400, 'INVALID_INPUT');
    }

    // TODO: Obtener usuario autenticado de la sesión
    $updatedBy = 2; // Por ahora, usar usuario de prueba

    try {
        $pdo = getDatabaseConnection();
        $templateService = new TemplateService($pdo);

        $savedFields = $templateService->saveTemplateFields($templateId, $input['campos'], $updatedBy);

        Response::success([
            'template_id' => $templateId,
            'campos' => $savedFields
        ], 'Coordenadas de campos guardadas correctamente');

    } catch (Exception $e) {
        Response::error(
            $e->getMessage(),
            400,
            'SAVE_FIELDS_ERROR'
        );
    }
}

/**
 * GET /admin/templates/available-fields - Obtiene los campos disponibles por tipo de plantilla
 */
function handleGetAvailableFields(): void
{
    Response::validateMethod('GET');

    Response::success([
        'base' => TemplateService::BASE_TEMPLATE_FIELDS,
        'curso' => TemplateService::COURSE_TEMPLATE_FIELDS
    ]);
}

/**
 * GET /admin/templates/{id}/preview-image - Obtiene imagen PNG de preview de una plantilla
 */
function handleGetTemplatePreviewImage(int $templateId): void
{
    Response::validateMethod('GET');

    try {
        $pdo = getDatabaseConnection();

        // Obtener datos de la plantilla
        $stmt = $pdo->prepare("
            SELECT tipo, imagen_preview
            FROM cc_plantillas
            WHERE id = ? AND activo = 1
        ");
        $stmt->execute([$templateId]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            Response::error('Plantilla no encontrada', 404, 'NOT_FOUND');
        }

        if (!$template['imagen_preview']) {
            Response::error('La plantilla no tiene imagen de preview', 404, 'NO_PREVIEW');
        }

        // Construir ruta del archivo
        $basePath = defined('TEMPLATES_PATH') ? TEMPLATES_PATH : BASE_PATH . '/storage/templates';
        $subDir = $template['tipo'] === 'base' ? 'base' : 'cursos';
        $imagePath = $basePath . '/' . $subDir . '/' . $template['imagen_preview'];

        if (!file_exists($imagePath)) {
            Response::error('Archivo de imagen no encontrado', 404, 'FILE_NOT_FOUND');
        }

        // Enviar imagen PNG
        header('Content-Type: image/png');
        header('Content-Length: ' . filesize($imagePath));
        header('Cache-Control: public, max-age=3600');
        header('Content-Disposition: inline; filename="' . $template['imagen_preview'] . '"');

        readfile($imagePath);
        exit;

    } catch (Exception $e) {
        Response::error(
            'Error obteniendo imagen de preview',
            500,
            'PREVIEW_IMAGE_ERROR',
            DEBUG_MODE ? ['error' => $e->getMessage()] : []
        );
    }
}
