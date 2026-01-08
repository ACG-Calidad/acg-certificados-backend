# ACG Certificados - Backend API

API REST en PHP 8.4 para el sistema de gestión de certificados de ACG Calidad.

## 📋 Descripción

Backend que proporciona 19 endpoints REST para:
- Autenticación con SSO desde Moodle
- Gestión de certificados (CRUD)
- Generación de PDFs con FPDF + FPDI
- Integración con Moodle Web Services
- Integración con Google Apps Script para notificaciones
- Validación pública de certificados

---

## 🛠️ Tecnologías

- **PHP:** 8.4.14
- **Servidor Web:** Apache 2.4.65
- **Base de Datos:** MariaDB 10.11.15 (AWS RDS)
- **Dependencias:**
  - `fpdf/fpdf`: ^1.86 (Generación de PDFs)
  - `setasign/fpdi`: ^2.6 (Importación de plantillas PDF)
  - `aws/aws-sdk-php`: ^3.0 (AWS Secrets Manager)
  - `firebase/php-jwt`: ^6.0 (Autenticación JWT)

---

## 📁 Estructura del Proyecto

```
backend/
├── api/                      # Endpoints de la API REST
│   ├── auth/                 # Autenticación (login, SSO, logout, refresh)
│   ├── certificates/         # Gestión de certificados
│   ├── admin/                # Administración (dashboard, reportes, config)
│   └── validation/           # Validación pública
├── lib/                      # Librerías y clases
│   ├── models/               # Modelos de datos (Certificate, Template, etc.)
│   └── services/             # Servicios (MoodleService, PdfService, etc.)
├── config/                   # Archivos de configuración
│   ├── config.php            # Configuración principal
│   └── config.example.php    # Ejemplo de configuración
├── storage/                  # Almacenamiento de archivos
│   ├── pdfs/                 # PDFs generados
│   ├── logs/                 # Logs de aplicación
│   └── temp/                 # Archivos temporales
├── scripts/                  # Scripts de utilidad
│   ├── migrate_database.php  # Migración de certificados legacy
│   └── calculate_legacy_grades.php
├── cron/                     # Tareas programadas
│   └── check_approved_users.php
├── tests/                    # Tests unitarios y de integración
├── public/                   # Punto de entrada público
│   └── index.php             # Router principal
├── composer.json             # Dependencias PHP
├── composer.lock
└── README.md
```

---

## 🚀 Instalación

### Prerrequisitos
- PHP 8.4.14 o superior
- Composer
- Extensiones PHP: pdo_mysql, mbstring, json, curl
- Acceso a base de datos MariaDB 10.11.15
- Acceso a Moodle 5.1 con Web Services habilitados

### Pasos

1. **Clonar repositorio**
```bash
git clone https://github.com/ACG-Calidad/acg-certificados-backend.git
cd acg-certificados-backend
```

2. **Instalar dependencias**
```bash
composer install
```

3. **Configurar aplicación**
```bash
cp config/config.example.php config/config.php
# Editar config.php con credenciales reales
```

4. **Ejecutar migración de base de datos**
```bash
php scripts/migrate_database.php
```

5. **Configurar permisos**
```bash
chmod -R 755 .
chmod -R 777 storage/pdfs
chmod -R 777 storage/logs
chmod -R 777 storage/temp
```

6. **Configurar Apache VirtualHost**
```apache
<VirtualHost *:80>
    ServerName certificados.acgcalidad.co
    DocumentRoot /var/www/html/certificados/public

    <Directory /var/www/html/certificados/public>
        AllowOverride All
        Require all granted
    </Directory>

    Alias /api /var/www/html/certificados/api
    <Directory /var/www/html/certificados/api>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/certificados-error.log
    CustomLog ${APACHE_LOG_DIR}/certificados-access.log combined
</VirtualHost>
```

7. **Crear archivo .htaccess en /api**
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ $1.php [L]
```

---

## 🔧 Configuración

### Configuración Principal (config/config.php)

```php
<?php
define('DB_HOST', 'moodle51-db.xxxxx.us-east-1.rds.amazonaws.com');
define('DB_NAME', 'moodle51');
define('DB_USER', 'certificates_app');
define('DB_PASS', 'SECRET'); // Obtener desde AWS Secrets Manager

define('MOODLE_URL', 'http://aulavirtual.acgcalidad.co');
define('MOODLE_TOKEN', 'SECRET'); // Obtener desde AWS Secrets Manager

define('JWT_SECRET', 'SECRET'); // Generar aleatorio y guardar en AWS Secrets
define('JWT_EXPIRATION', 3600); // 1 hora

define('PDF_STORAGE_PATH', __DIR__ . '/../storage/pdfs');
define('LOG_PATH', __DIR__ . '/../storage/logs');

define('GAS_WEBHOOK_URL', 'https://script.google.com/macros/s/xxxxx/exec');

define('ENVIRONMENT', 'production'); // production | staging | development
define('DEBUG_MODE', false);
```

### AWS Secrets Manager

Configurar secretos en AWS:

```bash
# Credenciales de base de datos
aws secretsmanager create-secret \
  --name acg/certificados/db \
  --secret-string '{"host":"...","user":"...","password":"..."}'

# Token de Moodle
aws secretsmanager create-secret \
  --name acg/certificados/moodle \
  --secret-string '{"token":"c1659f653f7bbe6f038f4b4e7b6fb585"}'

# JWT Secret
aws secretsmanager create-secret \
  --name acg/certificados/jwt \
  --secret-string '{"secret":"GENERAR_ALEATORIO"}'
```

---

## 📡 API Endpoints

Ver documentación completa: [Especificación de API](../../docs/34-ACG-Especificacion_API_Certificados.md)

### Autenticación
- `POST /api/auth/validate-moodle-token` - Validar token SSO de Moodle
- `POST /api/auth/login` - Login con credenciales
- `GET /api/auth/session` - Validar sesión actual
- `POST /api/auth/refresh` - Renovar token JWT
- `POST /api/auth/logout` - Cerrar sesión

### Certificados (Participante)
- `GET /api/certificates` - Listar certificados del usuario
- `GET /api/certificates/{id}/download` - Descargar PDF

### Gestión (Gestor/Admin)
- `GET /api/certificates/pending` - Usuarios aprobados sin certificado
- `POST /api/certificates/approve` - Aprobar generación de certificados
- `POST /api/certificates/generate` - Generar PDFs en lote
- `POST /api/certificates/notify` - Enviar notificaciones por email
- `GET /api/certificates/all` - Listar todos los certificados (admin)

### Validación Pública
- `GET /api/validation/{numero_certificado}` - Validar certificado por ID

### Administración
- `GET /api/admin/dashboard` - Estadísticas del dashboard
- `POST /api/admin/reports` - Generar reportes exportables
- `GET /api/admin/config` - Obtener configuración del sistema
- `POST /api/admin/config` - Actualizar configuración
- `GET /api/admin/plantillas` - Listar plantillas
- `POST /api/admin/plantillas` - Crear plantilla
- `PUT /api/admin/plantillas/{id}` - Actualizar plantilla
- `DELETE /api/admin/plantillas/{id}` - Eliminar plantilla

---

## 🔐 Autenticación

### Flujo SSO desde Moodle

1. Usuario hace clic en enlace en Moodle
2. Moodle genera token temporal (plugin `local_certificados_sso`)
3. Redirige a: `/certificados/?moodle_token=[TOKEN]`
4. Frontend Angular llama a `POST /api/auth/validate-moodle-token`
5. Backend valida token contra Moodle Web Services
6. Backend genera JWT propio (expiración: 1 hora)
7. Frontend guarda JWT en sessionStorage
8. Todas las peticiones subsecuentes incluyen JWT en header:
   ```
   Authorization: Bearer [JWT_TOKEN]
   ```

### Headers Requeridos

```http
Content-Type: application/json
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

---

## 🗄️ Base de Datos

### Tablas Principales

- `cc_certificados` - Certificados emitidos (incluye 1490 legacy)
- `cc_certificados_plantillas` - Plantillas de certificados
- `cc_certificados_log` - Auditoría de acciones
- `cc_certificados_config` - Configuración del sistema
- `cc_notificaciones_log` - Log de emails enviados

Ver esquema completo: [Arquitectura de Base de Datos](../../docs/33-ACG-Arquitectura_BD_Certificados.md)

---

## 🔨 Scripts de Utilidad

### Migración de Certificados Legacy

Migrar 1490 certificados existentes a nueva estructura:

```bash
php scripts/migrate_database.php
```

Este script:
1. Renombra `cc_certificados` a `cc_certificados_legacy`
2. Crea nueva estructura con 16 campos
3. Migra datos con transformaciones
4. Valida integridad de datos

### Calcular Calificaciones Legacy

```bash
php scripts/calculate_legacy_grades.php
```

Calcula y almacena calificaciones para certificados migrados que tienen `calificacion = NULL`.

---

## 📅 Tareas Programadas (Cron)

### Detección de Usuarios Aprobados

**Archivo:** `cron/check_approved_users.php`
**Horario:** Diariamente a las 7:00 AM
**Configuración:**

```bash
# Editar crontab
crontab -e

# Agregar línea:
0 7 * * * /usr/bin/php /var/www/html/certificados/cron/check_approved_users.php >> /var/log/certificates-cron.log 2>&1
```

**Función:**
- Detecta usuarios con cursos aprobados sin certificado
- Solo envía email si hay nuevos desde última ejecución
- Notifica al gestor con resumen

---

## 🧪 Testing

### Ejecutar Tests Unitarios

```bash
composer test
```

### Tests Manuales con Postman

Importar colección de Postman: `tests/postman/ACG_Certificados_API.postman_collection.json`

---

## 📊 Monitoreo y Logs

### Logs de Aplicación

```bash
tail -f storage/logs/app-$(date +%Y-%m-%d).log
```

### Logs de Apache

```bash
tail -f /var/log/httpd/certificados-error.log
tail -f /var/log/httpd/certificados-access.log
```

---

## 🚨 Troubleshooting

### Error: "Database connection failed"
- Verificar credenciales en `config/config.php`
- Verificar conectividad a RDS: `telnet moodle51-db.xxxxx.us-east-1.rds.amazonaws.com 3306`
- Verificar security groups de AWS

### Error: "Moodle Web Services not reachable"
- Verificar MOODLE_URL en config
- Verificar que Web Services estén habilitados en Moodle
- Verificar token de acceso

### Error: "PDF generation failed"
- Verificar permisos de escritura en `storage/pdfs/`
- Verificar librerías FPDF y FPDI instaladas
- Verificar plantilla de Google Drive accesible

---

## 🔒 Seguridad

- ✅ Validación de inputs en todos los endpoints
- ✅ Prevención de SQL injection (PDO prepared statements)
- ✅ Prevención de XSS (sanitización de outputs)
- ✅ Rate limiting por endpoint
- ✅ JWT con expiración de 1 hora
- ✅ Credenciales en AWS Secrets Manager (no en código)
- ✅ HTTPS en producción
- ✅ CORS configurado correctamente

---

## 📄 Licencia

Proyecto privado - ACG Calidad © 2026

---

## 👥 Contacto

**Desarrollador:** Oliver Castelblanco
**Soporte:** cursosvirtualesacg@gmail.com

---

*Última actualización: 2026-01-08*
