<?php
// Archivo que recibe las consultas desde JavaScript (guardar inscripciones, crear mesas, cargar pagos y notas)
// y las guarda o lee en la base de datos MySQL.
date_default_timezone_set('America/Argentina/Buenos_Aires');
require_once __DIR__ . "/conexion.php";
ini_set("display_errors", "0");

header("Content-Type: application/json; charset=UTF-8");

// Definimos qué acciones solo las puede hacer un directivo y cuáles también un alumno
$action = $_GET["action"] ?? "";
$soloDirectivoActions = ["create_mesa", "save_mesa", "delete_mesa", "clear_enrollments", "update_payment", "update_grade", "save_config"];
$accionesQueModifican = array_merge($soloDirectivoActions, ["save_enrollment"]);
$requiereDirectivo = in_array($action, $soloDirectivoActions, true);
$usuarioSesion = exigir_usuario_api($requiereDirectivo);

// Lo que modifica datos solo por POST (así exige la cabecera anti-CSRF de exigir_usuario_api)
if (in_array($action, $accionesQueModifican, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder_json(['success' => false, 'error' => 'Método no permitido'], 405);
}

// El detalle técnico va al log del servidor; al navegador solo un mensaje general
function error_generico(Throwable $e): string {
    error_log('preinscripciones/api.php: ' . $e->getMessage());
    return 'Error en la base de datos. Intente nuevamente.';
}

// Texto recibido del navegador: sin etiquetas HTML y con largo máximo
function texto_limpio($valor, int $max): string {
    return mb_substr(trim(strip_tags((string) $valor)), 0, $max);
}

try {
    $pdo = conectar();
    $dbname = defined("DB_NAME") ? DB_NAME : "campusdigitaldb";
} catch (Throwable $e) {
    error_generico($e);
    echo json_encode([
        "success" => false,
        "db_connected" => false,
        "message" => "Base de datos del campus no conectada"
    ]);
    exit;
}

function normalizeSubjectNamePHP($str) {
    if (!$str) return '';
    $str = mb_strtolower(trim($str), 'UTF-8');
    $accents = ['á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ñ'=>'n'];
    $str = strtr($str, $accents);
    $str = preg_replace('/[^\w\s]/u', ' ', $str);
    return trim(preg_replace('/\s+/', ' ', $str));
}

function getSubjectFamilyPHP($subjectName) {
    if (!$subjectName) return null;
    $norm = normalizeSubjectNamePHP($subjectName);
    if (str_starts_with($norm, 'lengua extranjera')) return null;
    if (str_starts_with($norm, 'ste ')) return null;
    if ($norm === 'matematica tecnica') return null;

    if (preg_match('/\s+(i{1,3}|iv|v|vi{0,3})$/i', trim($subjectName))) {
        $base = preg_replace('/\s+(i{1,3}|iv|v|vi{0,3})$/i', '', trim($subjectName));
        $normBase = normalizeSubjectNamePHP($base);
        if (str_contains($normBase, 'ingles tecnico')) return 'Inglés Técnico';
        if (str_contains($normBase, 'laboratorio')) return 'Laboratorio';
        if (str_contains($normBase, 'programacion')) return 'Programación';
        if (str_contains($normBase, 'contabilidad')) return 'Contabilidad';
        if (str_contains($normBase, 'matematica')) return 'Matemática';
        if (str_contains($normBase, 'computacion')) return 'Computación';
        if (str_contains($normBase, 'org de la prod')) return 'Org. de la Producción';
        if (str_contains($normBase, 'sist proc de datos')) return 'Sistemas Proc. de Datos';
        if (str_contains($normBase, 'sist adm contable')) return 'Sist. Adm. Contable';
        if (str_contains($normBase, 'estudio del prod')) return 'Estudio del Producto';
        if (str_contains($normBase, 'quimica aplicada')) return 'Química Aplicada';
        return $base;
    }

    if ($norm === 'matematica') return 'Matemática';
    if ($norm === 'contabilidad') return 'Contabilidad';
    return null;
}

function areSubjectsAllowedTogetherPHP($subjects) {
    if (!is_array($subjects) || count($subjects) <= 1) return true;
    $firstFamily = getSubjectFamilyPHP($subjects[0]);
    if (empty($firstFamily)) return false;
    foreach ($subjects as $s) {
        if (getSubjectFamilyPHP($s) !== $firstFamily) {
            return false;
        }
    }
    return true;
}

$action = $_GET['action'] ?? '';
$rawBody = file_get_contents('php://input');
$input = (!empty($rawBody) ? json_decode($rawBody, true) : null) ?? $_POST ?? [];

switch ($action) {
    case 'status':
    case 'test_connection':
        try {
            $pdo->query("SELECT 1");
            echo json_encode([
                'success' => true,
                'db_connected' => true,
                'message' => 'Conexión a MySQL exitosa'
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => error_generico($e)]);
        }
        break;

    case 'get_mesas':
        try {
            $stmt = $pdo->query("SELECT * FROM mesas_examen ORDER BY exam_date ASC, exam_time ASC");
            $rows = $stmt->fetchAll();
            $mesas = [];
            foreach ($rows as $m) {
                $subjArr = array_values(array_filter(array_map('trim', explode(',', (string)($m['subject_name'] ?? '')))));
                $tribParts = array_values(array_filter([
                    trim((string)($m['pres_docente'] ?? '')),
                    trim((string)($m['v1_docente'] ?? '')),
                    trim((string)($m['v2_docente'] ?? ''))
                ]));
                $mesas[] = [
                    'id' => $m['id'],
                    'subjectName' => $m['subject_name'],
                    'subject_name' => $m['subject_name'],
                    'subjectsArray' => $subjArr,
                    'course' => $m['course'],
                    'specialty' => $m['specialty'],
                    'examTurn' => $m['exam_turn'],
                    'exam_turn' => $m['exam_turn'],
                    'examDate' => $m['exam_date'],
                    'exam_date' => $m['exam_date'],
                    'examTime' => $m['exam_time'],
                    'exam_time' => $m['exam_time'],
                    'classroom' => $m['classroom'],
                    'tribunal' => implode(', ', $tribParts),
                    'presDocente' => $m['pres_docente'],
                    'pres_docente' => $m['pres_docente'],
                    'v1Docente' => $m['v1_docente'],
                    'v1_docente' => $m['v1_docente'],
                    'v2Docente' => $m['v2_docente'],
                    'v2_docente' => $m['v2_docente'],
                    'createdAt' => $m['created_at'],
                    'created_at' => $m['created_at']
                ];
            }
            echo json_encode(['success' => true, 'data' => $mesas]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => error_generico($e)]);
        }
        break;

    case 'create_mesa':
    case 'save_mesa':
        try {
            $id = $input['id'] ?? ('MESA-' . substr(time(), -4));
            $subjectName = trim($input['subjectName'] ?? $input['subject_name'] ?? '');
            $course = trim($input['course'] ?? '');
            $specialty = trim($input['specialty'] ?? '');
            $examTurn = trim($input['examTurn'] ?? $input['exam_turn'] ?? 'Febrero / Marzo');
            $examDate = trim($input['examDate'] ?? $input['exam_date'] ?? date('Y-m-d'));
            $examTime = trim($input['examTime'] ?? $input['exam_time'] ?? '08:00');
            $classroom = trim($input['classroom'] ?? 'Aula 1');
            $presDocente = trim($input['presDocente'] ?? $input['pres_docente'] ?? '');
            $v1Docente = trim($input['v1Docente'] ?? $input['v1_docente'] ?? '');
            $v2Docente = trim($input['v2Docente'] ?? $input['v2_docente'] ?? '');

            // Validación del Tribunal Docente (Obligatorio Nombre y Apellido, sin números)
            $tribunalCheck = [
                'Presidente de Mesa' => $presDocente,
                '1° Vocal' => $v1Docente,
                '2° Vocal' => $v2Docente
            ];
            foreach ($tribunalCheck as $roleName => $docName) {
                if (empty($docName)) {
                    echo json_encode(['success' => false, 'error' => "Debes completar el Nombre y Apellido del {$roleName}."]);
                    exit;
                }
                if (preg_match('/[^a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s]/u', $docName)) {
                    echo json_encode(['success' => false, 'error' => "El {$roleName} solo puede contener letras y espacios, sin números ni caracteres especiales."]);
                    exit;
                }
                $docWords = array_values(array_filter(preg_split('/\s+/', $docName)));
                if (count($docWords) < 2) {
                    echo json_encode(['success' => false, 'error' => "Debes ingresar Nombre y Apellido completo para el {$roleName}."]);
                    exit;
                }
            }

            // Revisamos que la fecha elegida para la mesa esté dentro de un rango válido
            if ($examDate > '2031-12-31') {
                echo json_encode(['success' => false, 'error' => 'La fecha de la mesa no puede ser posterior al 31/12/2031.']);
                exit;
            }

            // Controlamos que el horario de la mesa esté dentro del horario de la escuela (07:00 a 19:00)
            if (preg_match('/^(\d{1,2}):(\d{2})/', $examTime, $matches)) {
                $formattedTime = sprintf('%02d:%02d', (int)$matches[1], (int)$matches[2]);
                if ($formattedTime < '07:00' || $formattedTime > '19:00') {
                    echo json_encode(['success' => false, 'error' => 'La hora de inicio de la mesa debe estar entre las 07:00 y las 19:00 hs.']);
                    exit;
                }
                $examTime = $formattedTime;
            } else {
                echo json_encode(['success' => false, 'error' => 'Formato de hora inválido.']);
                exit;
            }

            // Evitamos que se mezclen cursos de Ciclo Básico (1ro a 3ro) con Ciclo Superior (4to a 7mo)
            $courseTokens = array_map('trim', explode(',', $course));
            $basicoList = ['1ro', '2do', '3ro', '1er AÑO', '2do AÑO', '3er AÑO'];
            $superiorList = ['4to', '5to', '6to', '7mo', '4to AÑO', '5to AÑO', '6to AÑO', '7mo AÑO'];

            $hasBasico = false;
            $hasSuperior = false;
            foreach ($courseTokens as $ct) {
                foreach ($basicoList as $b) {
                    if (stripos($ct, $b) !== false) { $hasBasico = true; }
                }
                foreach ($superiorList as $s) {
                    if (stripos($ct, $s) !== false) { $hasSuperior = true; }
                }
            }

            if ($hasBasico && $hasSuperior) {
                echo json_encode(['success' => false, 'error' => 'No se pueden combinar cursos de Ciclo Básico (1ro a 3ro) con Ciclo Superior (4to a 7mo) en una misma mesa.']);
                exit;
            }

            if ($hasSuperior) {
                if (empty($specialty) || !in_array($specialty, ['TAE', 'TC', 'TG'])) {
                    echo json_encode(['success' => false, 'error' => 'Para mesas de Ciclo Superior es obligatorio seleccionar una Tecnicatura válida (TAE, TC o TG).']);
                    exit;
                }
            } elseif ($hasBasico) {
                $specialty = ''; // Ciclo Básico no lleva tecnicatura
            }

            // Solo dejamos juntar más de una materia en la misma mesa si son correlativas (como Matemática I y II)
            $subjectList = [];
            if (!empty($input['subjectsArray']) && is_array($input['subjectsArray'])) {
                $subjectList = array_values(array_filter(array_map('trim', $input['subjectsArray'])));
            } elseif (!empty($subjectName)) {
                $subjectList = array_values(array_filter(array_map('trim', explode(',', $subjectName))));
            }

            if (empty($subjectList)) {
                echo json_encode(['success' => false, 'error' => 'Debes seleccionar al menos una materia para la mesa.']);
                exit;
            }

            if (count($subjectList) > 1 && !areSubjectsAllowedTogetherPHP($subjectList)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'No se puede elegir más de una materia para una mesa de examen, salvo que sean materias numeradas o correlativas de una misma familia (por ejemplo: Matemática y Matemática I).'
                ]);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO mesas_examen 
                (id, subject_name, course, specialty, exam_turn, exam_date, exam_time, classroom, pres_docente, v1_docente, v2_docente)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    subject_name = VALUES(subject_name),
                    course = VALUES(course),
                    specialty = VALUES(specialty),
                    exam_turn = VALUES(exam_turn),
                    exam_date = VALUES(exam_date),
                    exam_time = VALUES(exam_time),
                    classroom = VALUES(classroom),
                    pres_docente = VALUES(pres_docente),
                    v1_docente = VALUES(v1_docente),
                    v2_docente = VALUES(v2_docente)
            ");
            $stmt->execute([
                $id,
                $subjectName,
                $course,
                $specialty,
                $examTurn,
                $examDate,
                $examTime,
                $classroom,
                $presDocente,
                $v1Docente,
                $v2Docente
            ]);
            echo json_encode(['success' => true, 'id' => $id]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => error_generico($e)]);
        }
        break;

    case 'delete_mesa':
        try {
            $id = $_GET['id'] ?? $input['id'] ?? '';
            $stmt = $pdo->prepare("DELETE FROM mesas_examen WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => error_generico($e)]);
        }
        break;

    case 'get_enrollments':
        try {
            $turn = trim($_GET['turn'] ?? $_GET['exam_turn'] ?? '');
            $currentYear = intval(date('Y'));

            // El alumno solo ve sus propias inscripciones; el directivo, todas
            $filtroDni = '';
            $paramsDni = [];
            if ($usuarioSesion['rol'] !== 'directivo') {
                $dniPropio = (string) ($usuarioSesion['dni'] ?? '');
                if ($dniPropio === '') {
                    echo json_encode(['success' => true, 'data' => []]);
                    break;
                }
                $filtroDni = ' AND ai.student_dni = ?';
                $paramsDni = [$dniPropio];
            }

            if (!empty($turn)) {
                // Renovación por turno y año en curso (sin acumular instancias de años o llamados pasados)
                $stmt = $pdo->prepare("
                    SELECT DISTINCT ai.*
                    FROM alumnos_inscripciones ai
                    INNER JOIN inscripcion_materias im ON ai.id = im.inscripcion_id
                    WHERE im.exam_turn = ? AND (YEAR(ai.created_at) = ? OR ai.created_at IS NULL)$filtroDni
                    ORDER BY ai.created_at DESC
                ");
                $stmt->execute(array_merge([$turn, $currentYear], $paramsDni));
                $rows = $stmt->fetchAll();

                $subStmt = $pdo->prepare("SELECT * FROM inscripcion_materias WHERE inscripcion_id = ? AND exam_turn = ?");
            } else {
                $stmt = $pdo->prepare("
                    SELECT ai.* FROM alumnos_inscripciones ai
                    WHERE (YEAR(ai.created_at) = ? OR ai.created_at IS NULL)$filtroDni
                    ORDER BY ai.created_at DESC
                ");
                $stmt->execute(array_merge([$currentYear], $paramsDni));
                $rows = $stmt->fetchAll();
                $subStmt = $pdo->prepare("SELECT * FROM inscripcion_materias WHERE inscripcion_id = ?");
            }

            $enrollments = [];

            foreach ($rows as $rec) {
                if (!empty($turn)) {
                    $subStmt->execute([$rec['id'], $turn]);
                } else {
                    $subStmt->execute([$rec['id']]);
                }
                $subRows = $subStmt->fetchAll();
                $subs = [];
                foreach ($subRows as $s) {
                    $subs[] = [
                        'id' => (int)$s['id'],
                        'subjectName' => $s['subject_name'],
                        'subject_name' => $s['subject_name'],
                        'owedYear' => $s['subject_year'],
                        'subject_year' => $s['subject_year'],
                        'calendarYear' => $s['subject_family'] ?: '',
                        'subjectFamily' => $s['subject_family'],
                        'subject_family' => $s['subject_family'],
                        'examTurn' => $s['exam_turn'],
                        'exam_turn' => $s['exam_turn'],
                        'paid' => (bool)$s['paid'],
                        'grade' => $s['grade'] !== null ? (int)$s['grade'] : null
                    ];
                }

                if (!empty($turn) && empty($subs)) {
                    continue;
                }

                $enrollments[] = [
                    'id' => $rec['id'],
                    'alumnoId' => isset($rec['alumno_id']) ? (int)$rec['alumno_id'] : null,
                    'alumno_id' => isset($rec['alumno_id']) ? (int)$rec['alumno_id'] : null,
                    'studentName' => $rec['student_name'],
                    'student_name' => $rec['student_name'],
                    'studentDni' => $rec['student_dni'],
                    'student_dni' => $rec['student_dni'],
                    'currentCourse' => $rec['student_course'],
                    'student_course' => $rec['student_course'],
                    'studentPhone' => $rec['student_phone'],
                    'student_phone' => $rec['student_phone'],
                    'studentEmail' => $rec['student_email'],
                    'student_email' => $rec['student_email'],
                    'createdAt' => $rec['created_at'],
                    'created_at' => $rec['created_at'],
                    'paid' => (bool)$rec['paid'],
                    'subjects' => $subs
                ];
            }

            echo json_encode(['success' => true, 'data' => $enrollments]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => error_generico($e)]);
        }
        break;

    case 'save_enrollment':
        try {
            $esAlumno = $usuarioSesion['rol'] === 'alumno';
            $curso = texto_limpio($input['currentCourse'] ?? $input['studentCourse'] ?? $input['student_course'] ?? '', 50);

            // Límite reglamentario: los alumnos regulares (1ro a 7mo) rinden como máximo 2 materias previas;
            // los egresados pueden anotarse en todas las que adeuden
            $esEgresado = (stripos($curso, 'Egreso') !== false || stripos($curso, 'Egresad') !== false);
            if (!$esEgresado && !empty($input['subjects']) && is_array($input['subjects']) && count($input['subjects']) > 2) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Los alumnos regulares (de 1ro a 7mo año) solo pueden inscribirse a un máximo de 2 materias previas reglamentarias.'
                ]);
                break;
            }

            $id = texto_limpio($input['id'] ?? '', 50);
            $nombre = texto_limpio($input['studentName'] ?? $input['student_name'] ?? '', 150);
            $dni = preg_replace('/\D/', '', (string) ($input['studentDni'] ?? $input['student_dni'] ?? ''));
            $correo = texto_limpio($input['studentEmail'] ?? $input['student_email'] ?? '', 150);
            $pagado = !empty($input['paid']) ? 1 : 0;
            $alumnoId = (int) ($input['alumnoId'] ?? $input['alumno_id'] ?? 0);
            $previas = [];   // materia|turno => [paid, grade] ya guardados

            if ($esAlumno) {
                // El alumno se inscribe a sí mismo: nombre, DNI, correo e ID salen de su sesión, no del navegador
                $dni = (string) ($usuarioSesion['dni'] ?? '');
                if ($dni === '') {
                    responder_json(['success' => false, 'error' => 'No se pudo identificar tu DNI. Volvé a iniciar sesión.'], 403);
                }
                $nombre = (string) $usuarioSesion['nombre'];
                $correo = (string) (($usuarioSesion['correo'] ?? '') ?: $correo);
                $alumnoId = (int) ($usuarioSesion['id'] ?? 0);

                if ($id !== '') {
                    $st = $pdo->prepare('SELECT student_dni FROM alumnos_inscripciones WHERE id = ?');
                    $st->execute([$id]);
                    $dueno = $st->fetchColumn();
                    if ($dueno !== false && (string) $dueno !== $dni) {
                        responder_json(['success' => false, 'error' => 'No podés modificar la inscripción de otro alumno'], 403);
                    }
                    // Pagos y notas los carga el directivo: se conservan los que ya estaban
                    if ($dueno !== false) {
                        $st = $pdo->prepare('SELECT subject_name, exam_turn, paid, grade FROM inscripcion_materias WHERE inscripcion_id = ?');
                        $st->execute([$id]);
                        foreach ($st->fetchAll() as $p) {
                            $previas[$p['subject_name'] . '|' . $p['exam_turn']] = [(int) $p['paid'], $p['grade'] !== null ? (int) $p['grade'] : null];
                        }
                    }
                }
                if (!preg_match('/^INS-\d{6,16}$/', $id)) {
                    $id = 'INS-' . (int) (microtime(true) * 1000);
                }
            } elseif ($id === '') {
                $id = 'INS-' . (int) (microtime(true) * 1000);
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO alumnos_inscripciones (id, alumno_id, student_name, student_dni, student_course, student_phone, student_email, paid, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    alumno_id = VALUES(alumno_id),
                    student_name = VALUES(student_name),
                    student_dni = VALUES(student_dni),
                    student_course = VALUES(student_course),
                    student_phone = VALUES(student_phone),
                    student_email = VALUES(student_email),
                    paid = VALUES(paid)
            ");
            $stmt->execute([
                $id,
                $alumnoId > 0 ? $alumnoId : null,
                $nombre,
                $dni,
                $curso,
                texto_limpio($input['studentPhone'] ?? $input['student_phone'] ?? '', 50),
                $correo,
                $esAlumno ? 0 : $pagado
            ]);

            if (!empty($input['subjects']) && is_array($input['subjects'])) {
                // Eliminar materias previas para reemplazar limpiamente
                $delSub = $pdo->prepare("DELETE FROM inscripcion_materias WHERE inscripcion_id = ?");
                $delSub->execute([$id]);

                $subStmt = $pdo->prepare("
                    INSERT INTO inscripcion_materias (inscripcion_id, subject_name, subject_year, subject_family, exam_turn, paid, grade)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($input['subjects'] as $sub) {
                    if (!is_array($sub)) continue;
                    $materia = texto_limpio($sub['subjectName'] ?? $sub['subject_name'] ?? '', 150);
                    $turno = texto_limpio($sub['examTurn'] ?? $sub['exam_turn'] ?? 'Febrero / Marzo', 50);
                    if ($esAlumno) {
                        [$pagoMateria, $nota] = $previas[$materia . '|' . $turno] ?? [0, null];
                    } else {
                        $pagoMateria = !empty($sub['paid']) ? 1 : 0;
                        $notaRecibida = $sub['grade'] ?? null;
                        $nota = ($notaRecibida !== null && $notaRecibida !== '') ? intval($notaRecibida) : null;
                    }
                    $subStmt->execute([
                        $id,
                        $materia,
                        texto_limpio($sub['owedYear'] ?? $sub['subject_year'] ?? '', 50),
                        texto_limpio($sub['calendarYear'] ?? $sub['subjectFamily'] ?? $sub['subject_family'] ?? '', 100),
                        $turno,
                        $pagoMateria,
                        $nota
                    ]);
                }
            }

            if ($esAlumno) {
                // Estado general: abonado solo si el directivo ya registró el pago de todas sus materias
                $pend = $pdo->prepare('SELECT COUNT(*) FROM inscripcion_materias WHERE inscripcion_id = ? AND paid = 0');
                $pend->execute([$id]);
                $pdo->prepare('UPDATE alumnos_inscripciones SET paid = ? WHERE id = ?')
                    ->execute([(int) $pend->fetchColumn() === 0 && !empty($previas) ? 1 : 0, $id]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'id' => $id]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => error_generico($e)]);
        }
        break;

    case 'clear_enrollments':
        try {
            $turn = trim($input['turn'] ?? $_GET['turn'] ?? '');
            if (!empty($turn)) {
                $stmt = $pdo->prepare("DELETE FROM inscripcion_materias WHERE exam_turn = ?");
                $stmt->execute([$turn]);
                // Eliminar alumnos que ya no tengan materias asociadas
                $pdo->exec("DELETE FROM alumnos_inscripciones WHERE id NOT IN (SELECT DISTINCT inscripcion_id FROM inscripcion_materias)");
            } else {
                $pdo->exec("DELETE FROM inscripcion_materias");
                $pdo->exec("DELETE FROM alumnos_inscripciones");
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => error_generico($e)]);
        }
        break;

    case 'update_payment':
        try {
            // Los abonos solo pueden registrarse y modificarse una vez finalizado el período de preinscripción
            $cfgStmt = $pdo->query("SELECT clave, valor FROM configuracion");
            $cfgRows = $cfgStmt ? $cfgStmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
            $habilitada = ($cfgRows['preinscripcion_habilitada'] ?? '1') === '1';
            $fechaInicio = $cfgRows['preinscripcion_fecha_inicio'] ?? '';
            $fechaFin = $cfgRows['preinscripcion_fecha_fin'] ?? '';
            $today = date('Y-m-d');
            $isActive = $habilitada && (!$fechaInicio || $today >= $fechaInicio) && (!$fechaFin || $today <= $fechaFin);

            if ($isActive) {
                echo json_encode(['success' => false, 'error' => 'Los abonos solo pueden registrarse y modificarse una vez que finalice el período de preinscripción para los alumnos.']);
                break;
            }

            $enrollmentId = $input['enrollmentId'] ?? '';
            $subjectId = $input['subjectId'] ?? '';
            $isPaid = !empty($input['isPaid']) ? 1 : 0;

            if ($subjectId) {
                $stmt = $pdo->prepare("UPDATE inscripcion_materias SET paid = ? WHERE id = ? OR (inscripcion_id = ? AND subject_name = ?)");
                $stmt->execute([$isPaid, $subjectId, $enrollmentId, $subjectId]);
            }

            // Actualizar estado general del alumno
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM inscripcion_materias WHERE inscripcion_id = ? AND paid = 0");
            $checkStmt->execute([$enrollmentId]);
            $unpaidCount = $checkStmt->fetchColumn();

            $upd = $pdo->prepare("UPDATE alumnos_inscripciones SET paid = ? WHERE id = ?");
            $upd->execute([$unpaidCount == 0 ? 1 : 0, $enrollmentId]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => error_generico($e)]);
        }
        break;

    case 'update_grade':
        try {
            $enrollmentId = $input['enrollmentId'] ?? '';
            $subjectId = $input['subjectId'] ?? '';
            $grade = ($input['grade'] !== '' && $input['grade'] !== null) ? intval($input['grade']) : null;

            $stmt = $pdo->prepare("UPDATE inscripcion_materias SET grade = ? WHERE id = ? OR (inscripcion_id = ? AND subject_name = ?)");
            $stmt->execute([$grade, $subjectId, $enrollmentId, $subjectId]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => error_generico($e)]);
        }
        break;

    case 'get_config':
        try {
            // Asegurar que la tabla exista
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS configuracion (
                    clave VARCHAR(50) PRIMARY KEY,
                    valor TEXT,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $stmt = $pdo->query("SELECT clave, valor FROM configuracion");
            $rows = $stmt->fetchAll();
            $config = [];
            foreach ($rows as $r) {
                $config[$r['clave']] = $r['valor'];
            }

            // Defaults si no existen
            if (!isset($config['preinscripcion_habilitada'])) {
                $config['preinscripcion_habilitada'] = '1';
            }
            if (!isset($config['preinscripcion_fecha_inicio'])) {
                $config['preinscripcion_fecha_inicio'] = date('Y-01-01');
            }
            if (!isset($config['preinscripcion_fecha_fin'])) {
                $config['preinscripcion_fecha_fin'] = date('Y-12-31');
            }
            if (!isset($config['preinscripcion_importe'])) {
                $config['preinscripcion_importe'] = '3000';
            }

            echo json_encode(['success' => true, 'data' => $config]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => error_generico($e)]);
        }
        break;

    case 'save_config':
        try {
            // Asegurar que la tabla exista
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS configuracion (
                    clave VARCHAR(50) PRIMARY KEY,
                    valor TEXT,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $stmt = $pdo->prepare("
                INSERT INTO configuracion (clave, valor)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE valor = VALUES(valor)
            ");

            $pairs = [];

            if (isset($input['preinscripcion_habilitada'])) {
                $pairs['preinscripcion_habilitada'] = (!empty($input['preinscripcion_habilitada']) && $input['preinscripcion_habilitada'] !== '0' && $input['preinscripcion_habilitada'] !== 'false') ? '1' : '0';
            } elseif (isset($input['habilitada'])) {
                $pairs['preinscripcion_habilitada'] = (!empty($input['habilitada']) && $input['habilitada'] !== '0' && $input['habilitada'] !== 'false') ? '1' : '0';
            }

            if (isset($input['preinscripcion_fecha_inicio'])) {
                $pairs['preinscripcion_fecha_inicio'] = $input['preinscripcion_fecha_inicio'];
            } elseif (isset($input['fechaInicio'])) {
                $pairs['preinscripcion_fecha_inicio'] = $input['fechaInicio'];
            } elseif (isset($input['fecha_inicio'])) {
                $pairs['preinscripcion_fecha_inicio'] = $input['fecha_inicio'];
            }

            if (isset($input['preinscripcion_fecha_fin'])) {
                $pairs['preinscripcion_fecha_fin'] = $input['preinscripcion_fecha_fin'];
            } elseif (isset($input['fechaFin'])) {
                $pairs['preinscripcion_fecha_fin'] = $input['fechaFin'];
            } elseif (isset($input['fecha_fin'])) {
                $pairs['preinscripcion_fecha_fin'] = $input['fecha_fin'];
            }

            if (isset($input['preinscripcion_importe'])) {
                $pairs['preinscripcion_importe'] = (string)$input['preinscripcion_importe'];
            } elseif (isset($input['importe'])) {
                $pairs['preinscripcion_importe'] = (string)$input['importe'];
            }

            // Si se pasó un objeto genérico de settings
            if (isset($input['settings']) && is_array($input['settings'])) {
                foreach ($input['settings'] as $k => $v) {
                    $pairs[$k] = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
                }
            }

            // Validación de Importe de Inscripción (entre $2500 y $30000)
            if (isset($pairs['preinscripcion_importe'])) {
                $imp = floatval($pairs['preinscripcion_importe']);
                if ($imp < 2500 || $imp > 30000) {
                    echo json_encode(['success' => false, 'error' => 'El importe de inscripción debe ser un valor entre $2500 y $30000.']);
                    exit;
                }
            }

            // Requerimiento 2: Restricción de Fechas (hoy hasta 2031-12-31)
            $today = date('Y-m-d');
            $isEnabled = isset($pairs['preinscripcion_habilitada']) && ($pairs['preinscripcion_habilitada'] === '1' || $pairs['preinscripcion_habilitada'] === 1 || $pairs['preinscripcion_habilitada'] === true);

            // Si la preinscripción está habilitada, la fecha de inicio no puede ser menor a hoy. Si se deshabilita, no se restringe si es menor a hoy.
            if (!empty($pairs['preinscripcion_fecha_inicio'])) {

                if ($pairs['preinscripcion_fecha_inicio'] > '2031-12-31') {
                    echo json_encode(['success' => false, 'error' => 'La fecha de inicio no puede ser posterior al 31/12/2031.']);
                    exit;
                }
            }
            if (!empty($pairs['preinscripcion_fecha_fin'])) {
                if ($pairs['preinscripcion_fecha_fin'] < $today) {
                    echo json_encode(['success' => false, 'error' => 'La fecha de cierre no puede ser anterior a la fecha actual (' . $today . ').']);
                    exit;
                }
                if ($pairs['preinscripcion_fecha_fin'] > '2031-12-31') {
                    echo json_encode(['success' => false, 'error' => 'La fecha de cierre no puede ser posterior al 31/12/2031.']);
                    exit;
                }
            }
            if (!empty($pairs['preinscripcion_fecha_inicio']) && !empty($pairs['preinscripcion_fecha_fin'])) {
                if ($pairs['preinscripcion_fecha_inicio'] > $pairs['preinscripcion_fecha_fin']) {
                    echo json_encode(['success' => false, 'error' => 'La fecha de inicio no puede ser posterior a la fecha de fin.']);
                    exit;
                }
            }

            foreach ($pairs as $k => $v) {
                $stmt->execute([$k, $v]);
            }

            echo json_encode(['success' => true, 'updated' => $pairs]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => error_generico($e)]);
        }
        break;

    default:
        echo json_encode([
            'success' => true,
            'message' => 'API PHP EET N° 2 conectada a MySQL (eet2_mesas)',
            'available_actions' => ['status', 'get_mesas', 'create_mesa', 'delete_mesa', 'get_enrollments', 'save_enrollment', 'clear_enrollments', 'update_payment', 'update_grade', 'get_config', 'save_config']
        ]);
        break;
}