<?php
// api/index.php

session_start();
require 'conexion.php';

// --- Cabeceras para permitir peticiones desde cualquier origen (CORS) ---
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// --- Manejo de la petición pre-vuelo OPTIONS ---
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// --- Enrutador principal de la API ---
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'login': login($pdo); break;
    case 'logout': logout(); break;
    case 'get_session': get_session(); break;
    case 'get_data': get_initial_data($pdo); break;
    case 'add_employee': add_employee($pdo); break;
    case 'edit_employee': edit_employee($pdo); break; 
    case 'delete_employee': delete_employee($pdo); break;
    case 'add_user': add_user($pdo); break;
    case 'edit_user': edit_user($pdo); break;
    case 'delete_user': delete_user($pdo); break;
    case 'add_department': add_department($pdo); break;
    case 'edit_department': edit_department($pdo); break;
    case 'delete_department': delete_department($pdo); break;
    case 'get_punctuality_report': get_punctuality_report($pdo); break;
    case 'save_facial_descriptor': save_facial_descriptor($pdo); break;
    case 'check_in_out': check_in_out($pdo); break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Acción no válida']);
        break;
}

/**
 * Autentica a un usuario y crea una sesión.
 * @param PDO $pdo Instancia de la conexión a la base de datos.
 */
function login($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['username']) || !isset($data['password'])) {
        http_response_code(400); echo json_encode(['error' => 'Usuario y contraseña requeridos.']); return;
    }
    try {
        $stmt = $pdo->prepare("SELECT u.*, e.nombres, e.apellido_paterno FROM usuarios u LEFT JOIN empleados e ON u.id_empleado = e.id WHERE u.username = ?");
        $stmt->execute([$data['username']]);
        $user = $stmt->fetch();

        if ($user && password_verify($data['password'], $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['id_empleado'] = $user['id_empleado'];
            $_SESSION['full_name'] = !empty($user['nombres']) ? $user['nombres'] . ' ' . $user['apellido_paterno'] : $user['username'];
            
            // No enviar el hash de la contraseña al frontend
            unset($user['password_hash']);
            echo json_encode(['success' => true, 'user' => $user]);
        } else {
            http_response_code(401); echo json_encode(['error' => 'Usuario o contraseña incorrectos.']);
        }
    } catch (PDOException $e) {
        http_response_code(500); echo json_encode(['error' => 'Error en la base de datos: ' . $e->getMessage()]);
    }
}

/**
 * Cierra la sesión del usuario.
 */
function logout() {
    session_unset();
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Sesión cerrada.']);
}

/**
 * Verifica si existe una sesión activa y devuelve los datos del usuario.
 */
function get_session() {
    if (isset($_SESSION['user_id'])) {
        echo json_encode([
            'loggedIn' => true, 
            'user' => [
                'id' => $_SESSION['user_id'], 
                'username' => $_SESSION['username'], 
                'role' => $_SESSION['role'], 
                'id_empleado' => $_SESSION['id_empleado'], 
                'full_name' => $_SESSION['full_name']
            ]
        ]);
    } else {
        echo json_encode(['loggedIn' => false]);
    }
}

/**
 * Obtiene todos los datos iniciales necesarios para la aplicación.
 * @param PDO $pdo Instancia de la conexión a la base de datos.
 */
function get_initial_data($pdo) {
    try {
        // MEJORA: Se añade un LEFT JOIN a facial_descriptors para saber si un empleado tiene registro biométrico.
        $employees_sql = "
            SELECT 
                e.*, 
                d.nombre as departamento,
                CASE WHEN f.id_empleado IS NOT NULL THEN 1 ELSE 0 END AS has_biometric
            FROM empleados e 
            LEFT JOIN departamentos d ON e.id_departamento = d.id
            LEFT JOIN facial_descriptors f ON e.id = f.id_empleado
            ORDER BY e.nombres ASC
        ";
        $employees = $pdo->query($employees_sql)->fetchAll();
        $attendance = $pdo->query("SELECT * FROM registros_asistencia ORDER BY hora_entrada DESC")->fetchAll();
        $users = $pdo->query("SELECT id, username, role, id_empleado FROM usuarios")->fetchAll();
        $departments = $pdo->query("SELECT * FROM departamentos ORDER BY nombre ASC")->fetchAll();
        $facial_descriptors = $pdo->query("SELECT id_empleado, descriptor FROM facial_descriptors")->fetchAll();

        echo json_encode([
            'employees' => $employees, 
            'attendance' => $attendance, 
            'users' => $users, 
            'departments' => $departments, 
            'facial_descriptors' => $facial_descriptors
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al obtener los datos iniciales: ' . $e->getMessage()]);
    }
}

/**
 * Añade un nuevo empleado a la base de datos, incluyendo su fotografía.
 * @param PDO $pdo Instancia de la conexión a la base de datos.
 */
function add_employee($pdo) {
    // --- Validación y subida de la fotografía ---
    if (isset($_FILES['fotografia']) && $_FILES['fotografia']['error'] == 0) {
        $upload_dir = '../uploads/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $file_name = uniqid() . '-' . basename($_FILES['fotografia']['name']);
        $target_file = $upload_dir . $file_name;
        if (move_uploaded_file($_FILES['fotografia']['tmp_name'], $target_file)) {
            $photo_path = 'uploads/' . $file_name;
        } else { 
            http_response_code(500); echo json_encode(['error' => 'Error al subir la fotografía.']); return; 
        }
    } else { 
        http_response_code(400); echo json_encode(['error' => 'La fotografía es requerida.']); return; 
    }

    try {
        $sql = "INSERT INTO empleados (nombres, apellido_paterno, id_departamento, puesto, hora_entrada_oficial, managerId, fotografia) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        
        // --- Asignación de valores con manejo de nulos ---
        $managerId = !empty($_POST['managerId']) ? (int)$_POST['managerId'] : null;
        $id_departamento = !empty($_POST['id_departamento']) ? (int)$_POST['id_departamento'] : null;
        $hora_entrada_oficial = !empty($_POST['hora_entrada_oficial']) ? $_POST['hora_entrada_oficial'] : '09:00:00';
        
        $stmt->execute([$_POST['nombres'], $_POST['apellido_paterno'], $id_departamento, $_POST['puesto'], $hora_entrada_oficial, $managerId, $photo_path]);
        
        // --- Devolver el empleado recién creado ---
        $new_employee_id = $pdo->lastInsertId();
        $stmt_select = $pdo->prepare("SELECT e.*, d.nombre as departamento FROM empleados e LEFT JOIN departamentos d ON e.id_departamento = d.id WHERE e.id = ?");
        $stmt_select->execute([$new_employee_id]);
        $new_employee = $stmt_select->fetch();

        http_response_code(201);
        echo json_encode($new_employee);
    } catch (PDOException $e) {
        http_response_code(500); echo json_encode(['error' => 'Error al registrar el empleado: ' . $e->getMessage()]);
    }
}

/**
 * Edita la información de un empleado existente.
 * @param PDO $pdo Instancia de la conexión a la base de datos.
 */
function edit_employee($pdo) {
    if (!isset($_POST['id'])) {
        http_response_code(400); echo json_encode(['error' => 'ID de empleado requerido.']); return;
    }
    $id = (int)$_POST['id'];
    $photo_path = null;

    // --- Si se sube una nueva foto, se borra la anterior y se sube la nueva ---
    if (isset($_FILES['fotografia']) && $_FILES['fotografia']['error'] == 0) {
        $stmt_old_photo = $pdo->prepare("SELECT fotografia FROM empleados WHERE id = ?");
        $stmt_old_photo->execute([$id]);
        $old_employee = $stmt_old_photo->fetch();
        if ($old_employee && file_exists('../' . $old_employee['fotografia'])) {
            unlink('../' . $old_employee['fotografia']);
        }

        $upload_dir = '../uploads/';
        $file_name = uniqid() . '-' . basename($_FILES['fotografia']['name']);
        $target_file = $upload_dir . $file_name;
        if (move_uploaded_file($_FILES['fotografia']['tmp_name'], $target_file)) {
            $photo_path = 'uploads/' . $file_name;
        } else {
            http_response_code(500); echo json_encode(['error' => 'Error al subir la nueva fotografía.']); return;
        }
    }

    try {
        $managerId = !empty($_POST['managerId']) ? (int)$_POST['managerId'] : null;
        $id_departamento = !empty($_POST['id_departamento']) ? (int)$_POST['id_departamento'] : null;
        $hora_entrada_oficial = !empty($_POST['hora_entrada_oficial']) ? $_POST['hora_entrada_oficial'] : '09:00:00';
        
        $params = [$_POST['nombres'], $_POST['apellido_paterno'], $id_departamento, $_POST['puesto'], $hora_entrada_oficial, $managerId];
        $sql = "UPDATE empleados SET nombres = ?, apellido_paterno = ?, id_departamento = ?, puesto = ?, hora_entrada_oficial = ?, managerId = ?";
        
        if ($photo_path) {
            $sql .= ", fotografia = ?";
            $params[] = $photo_path;
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $id;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $stmt_select = $pdo->prepare("SELECT e.*, d.nombre as departamento FROM empleados e LEFT JOIN departamentos d ON e.id_departamento = d.id WHERE e.id = ?");
        $stmt_select->execute([$id]);
        $updated_employee = $stmt_select->fetch();

        echo json_encode($updated_employee);

    } catch (PDOException $e) {
        http_response_code(500); echo json_encode(['error' => 'Error al actualizar el empleado: ' . $e->getMessage()]);
    }
}

/**
 * Elimina un empleado y su fotografía del servidor.
 * @param PDO $pdo Instancia de la conexión a la base de datos.
 */
function delete_employee($pdo) {
    $id = (int)$_GET['id'];
    try {
        // --- Borrar la foto del servidor ---
        $stmt_select = $pdo->prepare("SELECT fotografia FROM empleados WHERE id = ?");
        $stmt_select->execute([$id]);
        $employee = $stmt_select->fetch();
        if ($employee && file_exists('../' . $employee['fotografia'])) {
            unlink('../' . $employee['fotografia']);
        }
        // --- Borrar el registro de la base de datos ---
        $stmt_delete = $pdo->prepare("DELETE FROM empleados WHERE id = ?");
        $stmt_delete->execute([$id]);
        if ($stmt_delete->rowCount() > 0) {
            echo json_encode(['success' => 'Empleado eliminado.']);
        } else {
            http_response_code(404); echo json_encode(['error' => 'Empleado no encontrado.']);
        }
    } catch (PDOException $e) {
        http_response_code(500); echo json_encode(['error' => 'Error al eliminar el empleado: ' . $e->getMessage()]);
    }
}

/**
 * Añade un nuevo usuario al sistema.
 * @param PDO $pdo Instancia de la conexión a la base de datos.
 */
function add_user($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    $password_hash = password_hash($data['password'], PASSWORD_DEFAULT);
    $id_empleado = !empty($data['id_empleado']) ? (int)$data['id_empleado'] : null;
    try {
        $sql = "INSERT INTO usuarios (username, password_hash, role, id_empleado) VALUES (?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$data['username'], $password_hash, $data['role'], $id_empleado]);
        $new_user_id = $pdo->lastInsertId();
        echo json_encode(['id' => $new_user_id, 'username' => $data['username'], 'role' => $data['role'], 'id_empleado' => $id_empleado]);
    } catch (PDOException $e) {
        // --- Manejo de error para usuario duplicado ---
        if ($e->errorInfo[1] == 1062) { http_response_code(409); echo json_encode(['error' => 'El nombre de usuario ya existe.']); }
        else { http_response_code(500); echo json_encode(['error' => 'Error al crear el usuario: ' . $e->getMessage()]); }
    }
}

/**
 * Edita un usuario existente.
 * @param PDO $pdo Instancia de la conexión a la base de datos.
 */
function edit_user($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['id'])) { http_response_code(400); echo json_encode(['error' => 'ID de usuario requerido.']); return; }
    $id = (int)$data['id'];
    $username = $data['username'];
    $role = $data['role'];
    $id_empleado = !empty($data['id_empleado']) ? (int)$data['id_empleado'] : null;
    try {
        $params = [$username, $role, $id_empleado];
        $sql = "UPDATE usuarios SET username = ?, role = ?, id_empleado = ?";
        // --- Actualizar contraseña solo si se proporciona una nueva ---
        if (!empty($data['password'])) {
            $password_hash = password_hash($data['password'], PASSWORD_DEFAULT);
            $sql .= ", password_hash = ?";
            $params[] = $password_hash;
        }
        $sql .= " WHERE id = ?";
        $params[] = $id;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'message' => 'Usuario actualizado.']);
    } catch (PDOException $e) {
        if ($e->errorInfo[1] == 1062) { http_response_code(409); echo json_encode(['error' => 'El nombre de usuario ya existe.']); }
        else { http_response_code(500); echo json_encode(['error' => 'Error al actualizar el usuario: ' . $e->getMessage()]); }
    }
}

/**
 * Elimina un usuario del sistema.
 * @param PDO $pdo Instancia de la conexión a la base de datos.
 */
function delete_user($pdo) {
    $id = (int)$_GET['id'];
    // --- Protección para no eliminar al admin principal ---
    if ($id === 1) { http_response_code(403); echo json_encode(['error' => 'No se puede eliminar al administrador principal.']); return; }
    try {
        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) { echo json_encode(['success' => 'Usuario eliminado.']); }
        else { http_response_code(404); echo json_encode(['error' => 'Usuario no encontrado.']); }
    } catch (PDOException $e) { http_response_code(500); echo json_encode(['error' => 'Error al eliminar el usuario: ' . $e->getMessage()]); }
}

/**
 * Añade un nuevo departamento.
 * @param PDO $pdo Instancia de la conexión a la base de datos.
 */
function add_department($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $sql = "INSERT INTO departamentos (nombre) VALUES (?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$data['nombre']]);
        $new_id = $pdo->lastInsertId();
        echo json_encode(['id' => $new_id, 'nombre' => $data['nombre']]);
    } catch (PDOException $e) {
        if ($e->errorInfo[1] == 1062) { http_response_code(409); echo json_encode(['error' => 'El departamento ya existe.']); }
        else { http_response_code(500); echo json_encode(['error' => 'Error al crear el departamento: ' . $e->getMessage()]); }
    }
}

/**
 * Edita un departamento existente.
 * @param PDO $pdo Instancia de la conexión a la base de datos.
 */
function edit_department($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $sql = "UPDATE departamentos SET nombre = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$data['nombre'], $data['id']]);
        echo json_encode(['success' => true, 'message' => 'Departamento actualizado.']);
    } catch (PDOException $e) {
        if ($e->errorInfo[1] == 1062) { http_response_code(409); echo json_encode(['error' => 'El departamento ya existe.']); }
        else { http_response_code(500); echo json_encode(['error' => 'Error al actualizar el departamento: ' . $e->getMessage()]); }
    }
}

/**
 * Elimina un departamento.
 * @param PDO $pdo Instancia de la conexión a la base de datos.
 */
function delete_department($pdo) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM departamentos WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) { echo json_encode(['success' => 'Departamento eliminado.']); }
        else { http_response_code(404); echo json_encode(['error' => 'Departamento no encontrado.']); }
    } catch (PDOException $e) { http_response_code(500); echo json_encode(['error' => 'Error al eliminar el departamento: ' . $e->getMessage()]); }
}

/**
 * Genera un reporte de puntualidad para un rango de fechas.
 * @param PDO $pdo Instancia de la conexión a la base de datos.
 */
function get_punctuality_report($pdo) {
    $start_date = isset($_GET['start']) ? $_GET['start'] : date('Y-m-01');
    $end_date = isset($_GET['end']) ? $_GET['end'] : date('Y-m-t');

    $sql_employees = "SELECT id, nombres, apellido_paterno, hora_entrada_oficial FROM empleados";
    $stmt_employees = $pdo->query($sql_employees);
    $employees = $stmt_employees->fetchAll(PDO::FETCH_ASSOC);

    $sql_attendance = "SELECT id_empleado, hora_entrada FROM registros_asistencia WHERE hora_entrada BETWEEN ? AND ?";
    $stmt_attendance = $pdo->prepare($sql_attendance);
    $stmt_attendance->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    // --- Agrupar registros por id_empleado para optimizar la búsqueda ---
    $attendance_records = $stmt_attendance->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);

    $report = [];

    foreach ($employees as $employee) {
        $employee_report = [
            'name' => $employee['nombres'] . ' ' . $employee['apellido_paterno'],
            'days' => []
        ];
        
        $period = new DatePeriod(
             new DateTime($start_date),
             new DateInterval('P1D'),
             (new DateTime($end_date))->modify('+1 day')
        );

        foreach ($period as $date) {
            $day_str = $date->format('Y-m-d');
            $day_of_week = $date->format('N'); // 1 (lunes) a 7 (domingo)
            // --- Omitir fines de semana del reporte ---
            if ($day_of_week >= 6) continue;

            $record_for_day = null;
            if (isset($attendance_records[$employee['id']])) {
                foreach ($attendance_records[$employee['id']] as $record) {
                    if (strpos($record['hora_entrada'], $day_str) === 0) {
                        $record_for_day = $record;
                        break;
                    }
                }
            }

            if ($record_for_day) {
                $check_in_time = new DateTime($record_for_day['hora_entrada']);
                $official_time = new DateTime($day_str . ' ' . $employee['hora_entrada_oficial']);
                
                if ($check_in_time > $official_time) {
                    $diff = $check_in_time->getTimestamp() - $official_time->getTimestamp();
                    $minutes_late = round($diff / 60);
                    $employee_report['days'][$day_str] = ['status' => 'Retardo', 'minutes' => $minutes_late];
                } else {
                    $employee_report['days'][$day_str] = ['status' => 'Puntual'];
                }
            } else {
                $employee_report['days'][$day_str] = ['status' => 'Ausencia'];
            }
        }
        $report[] = $employee_report;
    }

    echo json_encode($report);
}

/**
 * Guarda o actualiza el descriptor facial de un empleado.
 * @param PDO $pdo Instancia de la conexión a la base de datos.
 */
function save_facial_descriptor($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['id_empleado']) || !isset($data['descriptor'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos incompletos.']);
        return;
    }

    try {
        // --- REPLACE INTO inserta si no existe, o actualiza si ya existe (basado en la clave primaria) ---
        $sql = "REPLACE INTO facial_descriptors (id_empleado, descriptor) VALUES (?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$data['id_empleado'], json_encode($data['descriptor'])]);
        
        http_response_code(201);
        echo json_encode(['success' => true, 'message' => 'Perfil facial registrado con éxito.']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al guardar el perfil facial: ' . $e->getMessage()]);
    }
}

/**
 * Registra una entrada o salida para un empleado.
 * @param PDO $pdo Instancia de la conexión a la base de datos.
 */
function check_in_out($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    $employee_id = (int)$data['employeeId'];
    $now = date('Y-m-d H:i:s');
    $today_start = date('Y-m-d 00:00:00');
    $today_end = date('Y-m-d 23:59:59');
    try {
        // --- Buscar un registro de entrada abierto para hoy ---
        $sql = "SELECT id FROM registros_asistencia WHERE id_empleado = ? AND hora_entrada >= ? AND hora_entrada <= ? AND hora_salida IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$employee_id, $today_start, $today_end]);
        $existing_record = $stmt->fetch();

        if ($existing_record) {
            // --- Si existe, es una SALIDA ---
            $sql_update = "UPDATE registros_asistencia SET hora_salida = ? WHERE id = ?";
            $stmt_update = $pdo->prepare($sql_update);
            $stmt_update->execute([$now, $existing_record['id']]);
            echo json_encode(['status' => 'success', 'action' => 'check_out', 'timestamp' => $now]);
        } else {
            // --- Si no existe, es una ENTRADA ---
            $sql_insert = "INSERT INTO registros_asistencia (id_empleado, hora_entrada) VALUES (?, ?)";
            $stmt_insert = $pdo->prepare($sql_insert);
            $stmt_insert->execute([$employee_id, $now]);
            echo json_encode(['status' => 'success', 'action' => 'check_in', 'timestamp' => $now]);
        }
    } catch (PDOException $e) { 
        http_response_code(500); 
        echo json_encode(['error' => 'Error al registrar la asistencia: ' . $e->getMessage()]); 
    }
}
?>
