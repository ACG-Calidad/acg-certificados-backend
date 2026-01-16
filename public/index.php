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
        // Admin - Exportar reporte de certificados (Excel/CSV)
        // ========================================================================
        $requestMethod === 'GET' && $requestUri === '/admin/report/export' => handleExportReport(),

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
    $approved = 0;
    $certificatesCreated = 0;

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
        $stmt = $pdo->prepare("
            INSERT INTO cc_certificados
            (userid, courseid, numero_certificado, hash_validacion, fecha_emision, intensidad, calificacion, estado, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, UNIX_TIMESTAMP(), ?, ?, 'generado', ?, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
        ");
        $grade = $userData['grade'] ? round($userData['grade'], 2) : null;
        $createdBy = $userid; // TODO: obtener del usuario autenticado en sesión
        $stmt->execute([$userid, $courseId, $numeroCertificado, $hashValidacion, $intensidad, $grade, $createdBy]);

        $certificatesCreated++;
        $approved++;
    }

    Response::success([
        'approved' => $approved,
        'certificates_created' => $certificatesCreated
    ]);
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
