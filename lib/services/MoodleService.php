<?php

namespace ACG\Certificados\Services;

/**
 * Servicio para integración con Moodle Web Services
 *
 * Proporciona métodos para validar tokens SSO y obtener información de usuarios
 * desde la instancia de Moodle 5.1
 */
class MoodleService
{
    private string $moodleUrl;
    private string $wsToken;

    public function __construct()
    {
        $this->moodleUrl = MOODLE_URL;
        $this->wsToken = MOODLE_TOKEN;

        if (empty($this->wsToken)) {
            throw new \Exception('MOODLE_TOKEN no está configurado en config.php');
        }
    }

    /**
     * Valida un token SSO contra Moodle Web Service
     *
     * @param string $ssoToken Token temporal generado por el plugin local_certificados_sso
     * @return array|false Datos del usuario si el token es válido, false si no
     */
    public function validateSSOToken(string $ssoToken): array|false
    {
        if (empty($ssoToken)) {
            return false;
        }

        $params = [
            'wstoken' => $this->wsToken,
            'wsfunction' => 'local_certificados_sso_validate_token',
            'moodlewsrestformat' => 'json',
            'token' => $ssoToken
        ];

        try {
            $response = $this->callWebService($params);

            // Log de la respuesta completa para debugging
            $this->log('Respuesta del Web Service', [
                'response_type' => gettype($response),
                'response_keys' => is_array($response) ? array_keys($response) : null,
                'valid_field' => isset($response['valid']) ? $response['valid'] : 'NOT_SET',
                'token_length' => strlen($ssoToken)
            ]);

            // Si la respuesta es un array y tiene el campo 'valid'
            if (is_array($response) && isset($response['valid']) && $response['valid'] === true) {
                // Log exitoso
                $this->log('Token SSO validado exitosamente', [
                    'userid' => $response['userid'] ?? null,
                    'username' => $response['username'] ?? null
                ]);

                return $response;
            }

            // Token inválido o error
            $this->log('Token SSO inválido', [
                'token_length' => strlen($ssoToken),
                'response_valid' => isset($response['valid']) ? $response['valid'] : 'NOT_SET',
                'response_error' => isset($response['error']) ? $response['error'] : 'NO_ERROR'
            ]);
            return false;

        } catch (\Exception $e) {
            $this->log('Error validando token SSO', [
                'error' => $e->getMessage(),
                'token_length' => strlen($ssoToken)
            ], 'ERROR');

            return false;
        }
    }

    /**
     * Obtiene información de un usuario por su ID
     *
     * @param int $userid ID del usuario en Moodle
     * @return array|false Datos del usuario o false si no existe
     */
    public function getUserInfo(int $userid): array|false
    {
        $params = [
            'wstoken' => $this->wsToken,
            'wsfunction' => 'core_user_get_users_by_field',
            'moodlewsrestformat' => 'json',
            'field' => 'id',
            'values[0]' => $userid
        ];

        try {
            $response = $this->callWebService($params);

            if (is_array($response) && !empty($response) && isset($response[0])) {
                return $response[0];
            }

            return false;

        } catch (\Exception $e) {
            $this->log('Error obteniendo información de usuario', [
                'userid' => $userid,
                'error' => $e->getMessage()
            ], 'ERROR');

            return false;
        }
    }

    /**
     * Obtiene la calificación final de un usuario en un curso
     *
     * @param int $userid ID del usuario
     * @param int $courseid ID del curso
     * @return float|null Calificación (0-100) o null si no tiene calificación
     */
    public function getUserGrade(int $userid, int $courseid): ?float
    {
        $params = [
            'wstoken' => $this->wsToken,
            'wsfunction' => 'core_grades_get_grades',
            'moodlewsrestformat' => 'json',
            'courseid' => $courseid,
            'userids[0]' => $userid
        ];

        try {
            $response = $this->callWebService($params);

            // La respuesta tiene estructura compleja, buscar la calificación final
            if (is_array($response) && isset($response['items'])) {
                foreach ($response['items'] as $item) {
                    if ($item['itemtype'] === 'course') {
                        $grade = $item['grades'][0]['grade'] ?? null;
                        if ($grade !== null) {
                            return (float) $grade;
                        }
                    }
                }
            }

            return null;

        } catch (\Exception $e) {
            $this->log('Error obteniendo calificación', [
                'userid' => $userid,
                'courseid' => $courseid,
                'error' => $e->getMessage()
            ], 'ERROR');

            return null;
        }
    }

    /**
     * Llama a un Web Service de Moodle
     *
     * @param array $params Parámetros de la llamada (incluye wstoken, wsfunction, etc.)
     * @return mixed Respuesta decodificada del servicio
     * @throws \Exception Si hay error en la llamada o respuesta
     */
    private function callWebService(array $params)
    {
        $url = $this->moodleUrl . '/webservice/rest/server.php';

        // Construir query string
        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;

        // Log de la URL que se está llamando
        $this->log('Llamando a Web Service', [
            'url' => $url,
            'full_url_preview' => substr($fullUrl, 0, 150) . '...'
        ]);

        // Hacer request con cURL - NO seguir redirects
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // NO seguir redirects

        // Agregar Host header para que Moodle acepte la petición
        // Moodle verifica que la petición venga de su wwwroot
        $moodleHost = defined('MOODLE_HOST') ? MOODLE_HOST : 'localhost:8082';
        $moodleProto = defined('MOODLE_PROTO') ? MOODLE_PROTO : 'http';
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Host: {$moodleHost}",
            "X-Forwarded-Host: {$moodleHost}",
            "X-Forwarded-Proto: {$moodleProto}"
        ]);

        // En desarrollo, aceptar certificados SSL autofirmados
        if (ENVIRONMENT === 'development') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        // Verificar errores de cURL
        if ($response === false) {
            throw new \Exception("Error de cURL: {$curlError}");
        }

        // Verificar código HTTP final
        if ($httpCode !== 200) {
            throw new \Exception("HTTP {$httpCode}: Error en la llamada al Web Service");
        }

        // Decodificar JSON
        $data = json_decode($response, true);

        // Verificar si hay error en la respuesta de Moodle
        if (is_array($data) && isset($data['exception'])) {
            throw new \Exception(
                "Error de Moodle: {$data['message']} ({$data['errorcode']})"
            );
        }

        return $data;
    }

    /**
     * Registra un mensaje en el log
     *
     * @param string $message Mensaje a registrar
     * @param array $context Contexto adicional
     * @param string $level Nivel de log (DEBUG, INFO, WARNING, ERROR)
     */
    private function log(string $message, array $context = [], string $level = 'INFO'): void
    {
        if (!LOG_TO_FILE) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context) : '';

        $logMessage = "[{$timestamp}] [{$level}] MoodleService: {$message}";
        if ($contextStr) {
            $logMessage .= " | Context: {$contextStr}";
        }
        $logMessage .= PHP_EOL;

        $logFile = LOG_PATH . '/moodle-service.log';
        error_log($logMessage, 3, $logFile);
    }
}
