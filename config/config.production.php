<?php
/**
 * Configuración de Producción - Green
 * ACG Sistema de Gestión de Certificados
 *
 * Este archivo se copia como config.php en el servidor de producción.
 */

// ============================================================
// ENTORNO
// ============================================================
define('ENVIRONMENT', 'production');
define('DEBUG_MODE', false);

// ============================================================
// BASE DE DATOS (RDS MariaDB 10.11.15)
// ============================================================
define('DB_HOST', 'acgdb.c2uujyoezwbf.us-east-1.rds.amazonaws.com');
define('DB_PORT', '3306');
define('DB_NAME', 'moodle51');
define('DB_USER', 'moodle');
define('DB_PASS', 'm00dl3!');
define('DB_CHARSET', 'utf8mb4');
define('DB_PREFIX', 'mdl_');
define('CC_PREFIX', 'cc_');

// ============================================================
// MOODLE INTEGRATION
// ============================================================
// Backend y Moodle están en el mismo servidor Apache
// Usar ruta interna: http://localhost/aulavirtual
define('MOODLE_URL', 'http://localhost/aulavirtual');
define('MOODLE_TOKEN', 'bba5d733b0bc18ea2e61bf6ed9bd9072');
define('MOODLE_HOST', 'aulavirtual.acgcalidad.co');
define('MOODLE_PROTO', 'https');

// ============================================================
// JWT AUTHENTICATION
// ============================================================
define('JWT_SECRET', '5557a18a76fbca069857615bbc4d84cc0a87af5066f63c5819416ac5710fe065');
define('JWT_EXPIRATION', 3600); // 1 hora
define('JWT_ALGORITHM', 'HS256');

// ============================================================
// RUTAS DE ALMACENAMIENTO
// ============================================================
define('BASE_PATH', dirname(__DIR__));
define('PDF_STORAGE_PATH', BASE_PATH . '/storage/pdfs');
define('TEMPLATES_PATH', BASE_PATH . '/storage/templates');
define('FONTS_PATH', BASE_PATH . '/storage/fonts/fpdf/');
define('LOG_PATH', BASE_PATH . '/storage/logs');
define('TEMP_PATH', BASE_PATH . '/storage/temp');

// ============================================================
// GENERACION DE PDFs
// ============================================================
define('PDF_DEFAULT_TEMPLATE_ID', 1);
define('PDF_MAX_GENERATION_BATCH', 100);

// ============================================================
// GOOGLE APPS SCRIPT (configuracion via BD, no aqui)
// ============================================================
define('GAS_CERTIFICATES_URL', '');
define('GAS_API_KEY', '');
define('GAS_TIMEOUT', 90);
define('GAS_MAX_RETRIES', 1);

// ============================================================
// NOTIFICACIONES
// ============================================================
define('EMAIL_GESTOR', 'cursosvirtualesacg@gmail.com');
define('EMAIL_FROM_NAME', 'Grupo Capacitacion ACG');
define('CRON_HORA_EJECUCION', '07:00');

// ============================================================
// SEGURIDAD
// ============================================================
define('ENABLE_RATE_LIMITING', true);
define('RATE_LIMIT_DEFAULT', 100);
define('RATE_LIMIT_LOGIN', 20);
define('RATE_LIMIT_DOWNLOAD', 50);

define('ALLOWED_ORIGINS', [
    'https://aulavirtual.acgcalidad.co'
]);

// ============================================================
// AWS
// ============================================================
define('AWS_REGION', 'us-east-1');
define('AWS_USE_SECRETS_MANAGER', false);

// ============================================================
// LOGGING
// ============================================================
define('LOG_LEVEL', 'INFO');
define('LOG_TO_FILE', true);
define('LOG_TO_SYSLOG', false);

// ============================================================
// TIMEZONE
// ============================================================
date_default_timezone_set('America/Bogota');

// ============================================================
// ERROR HANDLING
// ============================================================
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

// ============================================================
// FPDF FONTS PATH
// ============================================================
define('FPDF_FONTPATH', FONTS_PATH);

// ============================================================
// AUTOLOAD
// ============================================================
require_once BASE_PATH . '/vendor/autoload.php';
