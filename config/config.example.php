<?php
/**
 * Configuración de la Aplicación de Certificados ACG
 *
 * Copiar este archivo a config.php y configurar con valores reales.
 * NUNCA commitear config.php con credenciales reales a Git.
 */

// ============================================================
// ENTORNO
// ============================================================
define('ENVIRONMENT', 'development'); // development | staging | production
define('DEBUG_MODE', true); // false en producción

// ============================================================
// BASE DE DATOS
// ============================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'moodle51');
define('DB_USER', 'certificates_app');
define('DB_PASS', 'YOUR_DB_PASSWORD_HERE');
define('DB_CHARSET', 'utf8mb4');

// En producción, obtener desde AWS Secrets Manager:
// $secrets = getAWSSecret('acg/certificados/db');
// define('DB_HOST', $secrets['host']);
// define('DB_USER', $secrets['user']);
// define('DB_PASS', $secrets['password']);

// ============================================================
// MOODLE INTEGRATION
// ============================================================
define('MOODLE_URL', 'http://localhost/moodle'); // Sin trailing slash
define('MOODLE_TOKEN', 'YOUR_MOODLE_WEBSERVICE_TOKEN_HERE');

// Token real de producción (NO commitear):
// define('MOODLE_TOKEN', 'c1659f653f7bbe6f038f4b4e7b6fb585');

// En producción, obtener desde AWS Secrets Manager:
// $secrets = getAWSSecret('acg/certificados/moodle');
// define('MOODLE_TOKEN', $secrets['token']);

// ============================================================
// JWT AUTHENTICATION
// ============================================================
define('JWT_SECRET', 'GENERATE_RANDOM_SECRET_HERE'); // 64+ caracteres
define('JWT_EXPIRATION', 3600); // 1 hora en segundos
define('JWT_ALGORITHM', 'HS256');

// Generar con: bin2hex(random_bytes(32))
// En producción, obtener desde AWS Secrets Manager:
// $secrets = getAWSSecret('acg/certificados/jwt');
// define('JWT_SECRET', $secrets['secret']);

// ============================================================
// RUTAS DE ALMACENAMIENTO
// ============================================================
define('BASE_PATH', dirname(__DIR__));
define('PDF_STORAGE_PATH', BASE_PATH . '/storage/pdfs');
define('LOG_PATH', BASE_PATH . '/storage/logs');
define('TEMP_PATH', BASE_PATH . '/storage/temp');

// ============================================================
// GENERACIÓN DE PDFs
// ============================================================
define('PDF_DEFAULT_TEMPLATE_ID', 1);
define('PDF_MAX_GENERATION_BATCH', 100); // Máximo PDFs por lote

// ============================================================
// GOOGLE APPS SCRIPT
// ============================================================
define('GAS_WEBHOOK_URL', 'https://script.google.com/macros/s/YOUR_SCRIPT_ID/exec');
define('GAS_TIMEOUT', 30); // Timeout en segundos
define('GAS_MAX_RETRIES', 3); // Reintentos en caso de error

// ============================================================
// NOTIFICACIONES
// ============================================================
define('EMAIL_GESTOR', 'cursosvirtualesacg@gmail.com');
define('EMAIL_FROM_NAME', 'Grupo Capacitación ACG');
define('CRON_HORA_EJECUCION', '07:00'); // Hora del cron job diario

// ============================================================
// SEGURIDAD
// ============================================================
define('ENABLE_RATE_LIMITING', true);
define('RATE_LIMIT_DEFAULT', 60); // requests por minuto
define('RATE_LIMIT_LOGIN', 5); // requests por minuto para login
define('RATE_LIMIT_DOWNLOAD', 10); // requests por minuto para descarga

define('ALLOWED_ORIGINS', [
    'http://localhost:4200', // Development Angular
    'http://aulavirtual.acgcalidad.co', // Production
    'https://aulavirtual.acgcalidad.co'
]);

// ============================================================
// AWS
// ============================================================
define('AWS_REGION', 'us-east-1');
define('AWS_USE_SECRETS_MANAGER', false); // true en producción

// ============================================================
// LOGGING
// ============================================================
define('LOG_LEVEL', 'DEBUG'); // DEBUG | INFO | WARNING | ERROR
define('LOG_TO_FILE', true);
define('LOG_TO_SYSLOG', false);

// ============================================================
// TIMEZONE
// ============================================================
date_default_timezone_set('America/Bogota');

// ============================================================
// ERROR HANDLING
// ============================================================
if (ENVIRONMENT === 'production') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

// ============================================================
// HELPER: Obtener Secreto de AWS Secrets Manager
// ============================================================
/**
 * Obtiene un secreto desde AWS Secrets Manager
 *
 * @param string $secretName Nombre del secreto
 * @return array Datos del secreto decodificados
 */
function getAWSSecret($secretName) {
    if (!AWS_USE_SECRETS_MANAGER) {
        return [];
    }

    try {
        $client = new \Aws\SecretsManager\SecretsManagerClient([
            'version' => '2017-10-17',
            'region' => AWS_REGION
        ]);

        $result = $client->getSecretValue([
            'SecretId' => $secretName
        ]);

        return json_decode($result['SecretString'], true);
    } catch (\Exception $e) {
        error_log("Error obteniendo secreto {$secretName}: " . $e->getMessage());
        return [];
    }
}

// ============================================================
// AUTOLOAD
// ============================================================
require_once BASE_PATH . '/vendor/autoload.php';
