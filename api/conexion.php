<?php
// api/conexion.php

/**
 * Este archivo contiene las credenciales de la base de datos y establece la conexión
 * utilizando PDO (PHP Data Objects) para mayor seguridad y compatibilidad.
 */

// Credenciales de la base de datos
$host = 'localhost';
$db_name = 'grupoam6_gotime';
$username = 'grupoam6_gotime';
$password = 'Cortometraje@3';
$charset = 'utf8mb4';

// Configuración del DSN (Data Source Name)
$dsn = "mysql:host=$host;dbname=$db_name;charset=$charset";

// Opciones de PDO para un manejo de errores robusto y un modo de obtención asociativo
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // Crear una nueva instancia de PDO para la conexión
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (\PDOException $e) {
    // Si la conexión falla, se detiene la ejecución y se envía una respuesta de error.
    // En un entorno de producción real, esto debería registrarse en un archivo de log en lugar de mostrarse al usuario.
    http_response_code(500);
    echo json_encode(['error' => 'Error de conexión a la base de datos.']);
    // Detener la ejecución del script para prevenir más errores.
    exit;
}
?>
