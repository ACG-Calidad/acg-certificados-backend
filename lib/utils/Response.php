<?php

namespace ACG\Certificados\Utils;

/**
 * Clase helper para generar respuestas JSON estandarizadas
 */
class Response
{
    /**
     * Envía una respuesta exitosa y termina la ejecución
     *
     * @param mixed $data Datos a retornar
     * @param string $message Mensaje descriptivo
     * @param int $httpCode Código HTTP (default: 200)
     */
    public static function success($data = null, string $message = '', int $httpCode = 200): void
    {
        http_response_code($httpCode);
        self::sendHeaders();

        $response = [
            'success' => true,
            'timestamp' => date('c')
        ];

        if ($message) {
            $response['message'] = $message;
        }

        if ($data !== null) {
            $response['data'] = $data;
        }

        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Envía una respuesta de error y termina la ejecución
     *
     * @param string $message Mensaje de error
     * @param int $httpCode Código HTTP (default: 400)
     * @param string $errorCode Código de error interno
     * @param array $details Detalles adicionales del error
     */
    public static function error(
        string $message,
        int $httpCode = 400,
        string $errorCode = '',
        array $details = []
    ): void {
        http_response_code($httpCode);
        self::sendHeaders();

        $response = [
            'success' => false,
            'error' => [
                'message' => $message
            ],
            'timestamp' => date('c')
        ];

        if ($errorCode) {
            $response['error']['code'] = $errorCode;
        }

        if (!empty($details)) {
            $response['error']['details'] = $details;
        }

        // En modo debug, agregar trace
        if (DEBUG_MODE) {
            $response['debug'] = [
                'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'timestamp' => time()
            ];
        }

        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Log del error
        self::logError($message, $httpCode, $errorCode, $details);

        exit;
    }

    /**
     * Envía headers HTTP estándar para JSON
     */
    private static function sendHeaders(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        // CORS headers
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = ALLOWED_ORIGINS;

        if (in_array($origin, $allowedOrigins) || in_array('*', $allowedOrigins)) {
            header("Access-Control-Allow-Origin: {$origin}");
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Id, X-User-Name, X-User-Role');
        header('Access-Control-Allow-Credentials: true');
    }

    /**
     * Maneja request OPTIONS (preflight CORS)
     */
    public static function handlePreflight(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            self::sendHeaders();
            exit;
        }
    }

    /**
     * Registra un error en el log
     *
     * @param string $message Mensaje de error
     * @param int $httpCode Código HTTP
     * @param string $errorCode Código de error
     * @param array $details Detalles adicionales
     */
    private static function logError(
        string $message,
        int $httpCode,
        string $errorCode,
        array $details
    ): void {
        if (!LOG_TO_FILE) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'] ?? '';

        $logMessage = "[{$timestamp}] [{$httpCode}] {$method} {$uri} - {$message}";

        if ($errorCode) {
            $logMessage .= " | Code: {$errorCode}";
        }

        if (!empty($details)) {
            $logMessage .= " | Details: " . json_encode($details);
        }

        $logMessage .= PHP_EOL;

        $logFile = LOG_PATH . '/api-errors.log';
        error_log($logMessage, 3, $logFile);
    }

    /**
     * Valida que el método HTTP sea el esperado
     *
     * @param string|array $expectedMethod Método esperado o array de métodos
     * @throws void Envía error 405 si el método no coincide
     */
    public static function validateMethod($expectedMethod): void
    {
        $currentMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $expectedMethods = is_array($expectedMethod) ? $expectedMethod : [$expectedMethod];

        if (!in_array($currentMethod, $expectedMethods)) {
            self::error(
                "Método {$currentMethod} no permitido. Esperado: " . implode(', ', $expectedMethods),
                405,
                'METHOD_NOT_ALLOWED'
            );
        }
    }

    /**
     * Obtiene el body del request como JSON
     *
     * @return array Datos del body parseados
     * @throws void Envía error 400 si el JSON es inválido
     */
    public static function getJsonBody(): array
    {
        $input = file_get_contents('php://input');

        if (empty($input)) {
            return [];
        }

        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            self::error(
                'JSON inválido en el body del request: ' . json_last_error_msg(),
                400,
                'INVALID_JSON'
            );
        }

        return $data ?? [];
    }
}
