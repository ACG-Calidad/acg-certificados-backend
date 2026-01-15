<?php
/**
 * Router Principal - API REST de Certificados ACG
 */

// Cargar configuración
require_once __DIR__ . '/../config/config.php';

use ACG\Certificados\Utils\Response;
use ACG\Certificados\Services\MoodleService;
use ACG\Certificados\Services\PdfService;

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
        // Inicializar servicio de PDF
        $pdfService = new PdfService();

        // Verificar si ya existe PDF generado
        $existingPdf = $pdfService->getCertificatePath($certificate['numero_certificado']);

        if ($existingPdf && file_exists($existingPdf)) {
            // Usar PDF existente
            $pdfPath = $existingPdf;
        } else {
            // Generar nuevo PDF
            $pdfPath = $pdfService->generateCertificate($certificate);
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
 * GET /api/certificates/validate/{code} - Validar certificado por código
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
            co.fullname as course_name,
            co.shortname as course_shortname
        FROM cc_certificados c
        INNER JOIN mdl_course co ON c.courseid = co.id
        WHERE c.numero_certificado = :code
        AND c.estado = 'aprobado'
    ");

    $stmt->execute(['code' => $code]);
    $certificate = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$certificate) {
        Response::json([
            'valid' => false,
            'error' => 'Certificado no encontrado o no válido'
        ]);
        return;
    }

    $certificate['fecha_emision_formatted'] = date('d/m/Y', $certificate['fecha_emision']);

    Response::json([
        'valid' => true,
        'certificate' => $certificate
    ]);
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

    // Pendientes (usuarios que completaron curso pero no tienen certificado)
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT cc.userid)
        FROM mdl_course_completions cc
        LEFT JOIN cc_certificados cert ON cc.userid = cert.userid AND cc.course = cert.courseid
        WHERE cc.timecompleted IS NOT NULL
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
 */
function handlePendingCertificates(): void
{
    Response::validateMethod('GET');

    $pdo = getDatabaseConnection();

    // Usuarios que completaron cursos pero no tienen certificado
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
            cc.timecompleted as completion_date,
            gg.finalgrade as grade
        FROM mdl_course_completions cc
        INNER JOIN mdl_user u ON cc.userid = u.id
        INNER JOIN mdl_course c ON cc.course = c.id
        LEFT JOIN mdl_grade_items gi ON gi.courseid = c.id AND gi.itemtype = 'course'
        LEFT JOIN mdl_grade_grades gg ON gg.itemid = gi.id AND gg.userid = u.id
        LEFT JOIN cc_certificados cert ON cc.userid = cert.userid AND cc.course = cert.courseid
        WHERE cc.timecompleted IS NOT NULL
        AND cert.id IS NULL
        ORDER BY cc.timecompleted DESC
        LIMIT 100
    ");

    $pendingUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatear fechas
    foreach ($pendingUsers as &$user) {
        $user['completion_date_formatted'] = date('Y-m-d H:i:s', $user['completion_date']);
        $user['grade'] = $user['grade'] ? round($user['grade'], 2) : null;
    }

    Response::success([
        'pending_users' => $pendingUsers,
        'total' => count($pendingUsers)
    ]);
}

// ============================================================================
// HELPERS
// ============================================================================

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
