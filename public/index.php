<?php
/**
 * Punto de entrada del backend - ACG Certificados
 */

// Cargar configuración
require_once __DIR__ . '/../config/config.php';

// Headers CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Información del backend
$response = [
    'status' => 'ok',
    'message' => 'ACG Certificados API - Backend funcionando correctamente',
    'version' => '1.0.0',
    'environment' => ENVIRONMENT,
    'timestamp' => date('Y-m-d H:i:s'),
    'endpoints' => [
        'auth' => '/api/auth/',
        'certificates' => '/api/certificates/',
        'admin' => '/api/admin/',
        'validation' => '/api/validation/'
    ],
    'database' => [
        'host' => DB_HOST,
        'name' => DB_NAME,
        'connected' => false
    ]
];

// Probar conexión a base de datos
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $response['database']['connected'] = true;
    $response['database']['version'] = $pdo->query('SELECT VERSION()')->fetchColumn();
} catch (PDOException $e) {
    $response['database']['error'] = $e->getMessage();
}

// Información de PHP
$response['php'] = [
    'version' => PHP_VERSION,
    'extensions' => [
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'mysqli' => extension_loaded('mysqli'),
        'mbstring' => extension_loaded('mbstring'),
        'gd' => extension_loaded('gd'),
        'zip' => extension_loaded('zip'),
        'intl' => extension_loaded('intl')
    ]
];

echo json_encode($response, JSON_PRETTY_PRINT);
