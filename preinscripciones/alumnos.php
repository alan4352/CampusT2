<?php
// Pantalla de preinscripción para alumnos.
// Usa la sesión del campus virtual para validar que quien entra tenga rol de alumno.
require_once __DIR__ . '/conexion.php';

header('Content-Type: text/html; charset=UTF-8');
date_default_timezone_set('America/Argentina/Buenos_Aires');

$usuario = null;
try {
    $usuario = usuario_mesas('alumno');
} catch (Throwable $e) {
    error_log('alumnos.php (usuario): ' . $e->getMessage());
    if (estaLogueado() && ($_SESSION['rol'] ?? '') === 'alumno') {
        $usuario = [
            'rol'    => 'alumno',
            'nombre' => trim((string) $_SESSION['nombre']),
            'dni'    => '',
            'correo' => '',
        ];
    }
}

// Si no es alumno, revisamos si es directivo para mandarlo a su panel; si no, vuelve al login
if ($usuario === null) {
    if (estaLogueado() && ($_SESSION['rol'] ?? '') === 'directivo') {
        header('Location: administrativo.php');
        exit;
    }
    header('Location: ' . URL_CAMPUS);
    exit;
}
session_write_close();

// Cargamos los datos del alumno que inició sesión para mostrarlos en el formulario
$nombreAlumno = $usuario['nombre'] !== '' ? $usuario['nombre'] : 'Alumno';
$dniAlumno    = $usuario['dni'] !== '' ? $usuario['dni'] : '40123456';
$correoAlumno = $usuario['correo'] !== '' ? $usuario['correo'] : 'alumno@gmail.com';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preinscripción a Mesas de Examen - Alumnos | EET N°2</title>
    
    <!-- Fuentes e íconos -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Montserrat:wght@600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    
    <link rel="stylesheet" href="styles.css?v=<?= filemtime(__DIR__ . '/styles.css') ?>">
    <script src="src/js/tema.js?v=<?= filemtime(__DIR__ . '/src/js/tema.js') ?>"></script>

    <!-- Librerías para armar y descargar el permiso de examen en PDF -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>
<body>

    <main class="main-container">

        <!-- Barra superior con el nombre del alumno y botón para volver al campus o salir -->
        <div class="no-print" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; width: 100%; margin-bottom: 1rem; padding: 0.65rem 1.1rem; border-radius: 12px; background: rgba(0, 0, 0, 0.35); border: 1px solid rgba(212, 175, 55, 0.35);">
            <a href="<?= e(URL_PANEL_CAMPUS) ?>" style="color: var(--color-gold); text-decoration: none; font-weight: 700; font-size: 0.88rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                <span class="material-symbols-outlined" style="font-size: 1.1rem;">arrow_back</span>
                Volver al Campus Virtual
            </a>
            <div style="display: flex; align-items: center; gap: 0.85rem; flex-wrap: wrap;">
                <div id="lugarTema" class="lugar-tema"></div>
                <span style="color: #fff; font-size: 0.88rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.35rem;">
                    <span class="material-symbols-outlined" style="font-size: 1.1rem; color: var(--color-gold);">school</span>
                    <?= e($nombreAlumno) ?> · <span style="color: var(--color-gold);">Alumno/a</span>
                </span>
                <a href="<?= e(URL_LOGOUT_CAMPUS) ?>" style="color: #ffcdd2; text-decoration: none; font-size: 0.82rem; font-weight: 700; padding: 0.3rem 0.75rem; border-radius: 8px; border: 1px solid rgba(239, 83, 80, 0.5); background: rgba(183, 28, 28, 0.25);">
                    Cerrar sesión
                </a>
            </div>
        </div>
        
        <header class="school-header">
            <div class="escudo-wrapper">
                <img src="imagenes/logo.png" class="escudo" alt="Escudo Escuela Técnica N° 2">
            </div>
            <h1>Preinscripción a Mesas de Examen</h1>
        </header>

        <!-- Contenedor principal del formulario -->
        <section class="auth-form" id="enrollmentFormSection">

            <!-- Aviso verde cuando el período de inscripción está abierto -->
            <div id="preinscripcionOpenNotice" class="preinscripcion-open-badge" style="display: none; margin-bottom: 1.25rem; padding: 0.8rem 1.2rem; border-radius: 10px; background: rgba(76, 175, 80, 0.12); border: 1px solid rgba(76, 175, 80, 0.35); color: #81c784; font-size: 0.88rem; align-items: center; gap: 0.6rem;">
                <span class="material-symbols-outlined" style="font-size: 1.3rem;">event_available</span>
                <span id="openNoticeText">Período de preinscripción habilitado: del <strong id="openNoticeStartDate">--/--/----</strong> al <strong id="openNoticeEndDate">--/--/----</strong></span>
            </div>

            <!-- Aviso cuando las inscripciones están cerradas o fuera de fecha -->
            <div id="preinscripcionClosedNotice" class="form-card-block preinscripcion-closed-card" style="display: none; text-align: center; padding: 2.5rem 1.5rem; margin-bottom: 1.5rem;">
                <div style="width: 70px; height: 70px; margin: 0 auto 1.25rem auto; border-radius: 50%; background: rgba(229, 57, 53, 0.15); border: 2px solid rgba(229, 57, 53, 0.4); display: flex; align-items: center; justify-content: center;">
                    <span class="material-symbols-outlined" style="font-size: 2.5rem; color: #ef5350;">lock_clock</span>
                </div>
                <h2 style="font-size: 1.4rem; color: #fff; margin-bottom: 0.6rem; font-weight: 700;">Preinscripciones a Mesas Cerradas</h2>
                <p id="closedNoticeReason" style="max-width: 560px; margin: 0 auto 1.25rem auto; color: var(--color-text-muted); font-size: 0.95rem; line-height: 1.5;">
                    El período de preinscripción a mesas de examen no se encuentra disponible actualmente. Las solicitudes de inscripción solo se habilitan en las fechas autorizadas por el equipo directivo.
                </p>
                <div id="closedNoticeDatesBox" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1.1rem; border-radius: 20px; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); font-size: 0.85rem; color: var(--color-gold); margin-bottom: 1.25rem;">
                    <span class="material-symbols-outlined" style="font-size: 1.1rem;">calendar_month</span>
                    <span id="closedNoticeDatesText">Próximo período establecido: Consultar en la institución</span>
                </div>
                <div style="font-size: 0.8rem; color: rgba(255,255,255,0.5);">
                    Si crees que esto es un error, por favor acércate a la Secretaría de la institución.
                </div>
            </div>

            <form id="enrollmentForm" onsubmit="return false;" novalidate>
                
                <!-- Paso 1: Datos personales del alumno -->
                <div class="form-card-block" id="studentDataBlock">
                    <div class="section-title">
                        <span class="material-symbols-outlined">account_circle</span>
                        Datos del Alumno
                    </div>

                    <div class="form-row-2col">
                        <div class="form-group">
                            <label for="studentName" class="form-label">
                                <span class="material-symbols-outlined">badge</span>
                                Nombre y Apellido *
                            </label>
                            <input type="text" id="studentName" class="form-control" value="<?= e($nombreAlumno) ?>" readonly required>
                        </div>

                        <div class="form-group">
                            <label for="studentDni" class="form-label">
                                <span class="material-symbols-outlined">id_card</span>
                                D.N.I. N° *
                            </label>
                            <input type="text" id="studentDni" class="form-control" value="<?= e($dniAlumno) ?>" readonly maxlength="8" inputmode="numeric" required>
                        </div>
                    </div>

                    <div class="form-row-2col">
                        <div class="form-group">
                            <label for="studentPhone" class="form-label">
                                <span class="material-symbols-outlined">call</span>
                                Teléfono de Contacto *
                            </label>
                            <input type="text" id="studentPhone" class="form-control" placeholder="Ej. 0345 412-3456" value="0345 412-3456" inputmode="numeric" maxlength="14" required autocomplete="off">
                        </div>

                        <div class="form-group">
                            <label for="studentBirthdate" class="form-label">
                                <span class="material-symbols-outlined">cake</span>
                                Fecha de Nacimiento *
                            </label>
                            <input type="date" id="studentBirthdate" class="form-control" min="<?= e((date('Y') - 32) . '-01-01') ?>" max="<?= e(date('Y-m-d', strtotime('-12 years'))) ?>" value="2005-04-15" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="studentEmail" class="form-label">
                            <span class="material-symbols-outlined">mail</span>
                            Correo Electrónico (Mail) *
                        </label>
                        <input type="email" id="studentEmail" class="form-control" placeholder="Ej. alumno@correo.com" value="<?= e($correoAlumno) ?>" readonly required autocomplete="off">
                    </div>
                </div>

                <!-- Paso 2: Curso actual y especialidad -->
                <div class="form-card-block" id="academicStatusBlock">
                    <div class="section-title">
                        <span class="material-symbols-outlined">school</span>
                        Situación Académica Actual
                    </div>

                    <div class="form-row-2col">
                        <div class="form-group">
                            <label for="currentCourse" class="form-label">
                                <span class="material-symbols-outlined">workspace_premium</span>
                                Curso Actual *
                            </label>
                            <select id="currentCourse" class="form-select" required>
                                <option value="" disabled selected>-- Seleccionar Curso --</option>
                                <option value="1ro">1er AÑO</option>
                                <option value="2do">2do AÑO</option>
                                <option value="3ro">3er AÑO</option>
                                <option value="4to">4to AÑO</option>
                                <option value="5to">5to AÑO</option>
                                <option value="6to">6to AÑO</option>
                                <option value="7mo">7mo AÑO</option>
                                <option value="Egreso">Egresado</option>
                            </select>
                        </div>

                        <!-- Se muestra recién cuando elige un curso de Ciclo Superior (4to a 7mo o Egresado) -->
                        <div class="form-group conditional-field" id="specialtyContainer" style="margin-bottom: 0;">
                            <label for="currentSpecialty" class="form-label">
                                <span class="material-symbols-outlined">engineering</span>
                                Tecnicatura *
                            </label>
                            <select id="currentSpecialty" class="form-select">
                                <option value="" disabled selected>-- Seleccionar --</option>
                                <option value="TAE">TAE - Adm. de Empresas</option>
                                <option value="TC">TC - Computación</option>
                                <option value="TG">TG - Gastronomía</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Paso 3: Selección de la materia previa que va a rendir -->
                <div class="form-card-block" id="owedSubjectBlock">
                    <div class="section-title">
                        <span class="material-symbols-outlined">menu_book</span>
                        Materia Adeudada a Rendir
                    </div>

                    <div class="form-row-2col">
                        <div class="form-group">
                            <label for="owedYear" class="form-label">
                                <span class="material-symbols-outlined">history_edu</span>
                                Curso donde adeudo la materia *
                            </label>
                            <select id="owedYear" class="form-select" required>
                                <option value="" disabled selected>-- Seleccionar Año --</option>
                                <option value="1er AÑO" data-level="1">1er AÑO</option>
                                <option value="2do AÑO" data-level="2">2do AÑO</option>
                                <option value="3er AÑO" data-level="3">3er AÑO</option>
                                <option value="4to" data-level="4">4to AÑO</option>
                                <option value="5to" data-level="5">5to AÑO</option>
                                <option value="6to" data-level="6">6to AÑO</option>
                                <option value="7mo" data-level="7">7mo AÑO</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="calendarYear" class="form-label">
                                <span class="material-symbols-outlined">calendar_month</span>
                                Año donde se adeudó la materia *
                            </label>
                            <select id="calendarYear" class="form-select" required>
                                <option value="2025" selected>2025</option>
                                <option value="2024">2024</option>
                                <option value="2023">2023</option>
                                <option value="2022">2022</option>
                                <option value="2021">2021</option>
                                <option value="2020">2020</option>
                                <option value="2019">2019</option>
                                <option value="2018">2018</option>
                                <option value="2017">2017</option>
                                <option value="2016">2016</option>
                                <option value="2015">2015</option>
                                <option value="2014">2014</option>
                                <option value="2013">2013</option>
                                <option value="2012">2012</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row-2col">
                        <!-- Las materias se cargan dinámicamente según el año y la tecnicatura elegida -->
                        <div class="form-group">
                            <label for="owedSubjectInput" class="form-label">
                                <span class="material-symbols-outlined">book</span>
                                Asignatura / Materia *
                            </label>
                            <select id="owedSubjectInput" class="form-select" required>
                                <option value="" disabled selected>-- Seleccionar Materia --</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="examTurn" class="form-label">
                                <span class="material-symbols-outlined">event</span>
                                Turno de Examen *
                            </label>
                            <select id="examTurn" class="form-select" disabled required>
                                <option value="Febrero / Marzo">Febrero / Marzo</option>
                                <option value="Julio / Agosto">Julio / Agosto</option>
                                <option value="Noviembre / Diciembre">Noviembre / Diciembre</option>
                            </select>
                            <input type="hidden" id="hiddenExamTurn" name="examTurn" value="Febrero / Marzo">
                            <div class="hint-text"><span class="material-symbols-outlined">lock</span> Turno asignado automáticamente según la fecha actual</div>
                        </div>
                    </div>
                </div>

                <div class="subject-form-actions">
                    <button type="submit" class="btn-ingresar" id="btnAddSubject" style="margin-top: 0; flex: 1; min-width: 250px;">
                        <span class="material-symbols-outlined" id="btnAddSubjectIcon" style="font-size: 1.4rem;">add_circle</span>
                        <span id="btnAddSubjectText">Agregar Materia a Solicitud</span>
                    </button>
                    <button type="button" class="btn-cancel-edit" id="btnCancelEditSubject" style="display: none;">
                        <span class="material-symbols-outlined" style="font-size: 1.2rem;">close</span>
                        <span>Cancelar Edición</span>
                    </button>
                </div>
            </form>

            <!-- Lista de materias que el alumno fue agregando antes de generar el PDF -->
            <div id="addedSubjectsContainer" class="added-subjects-container" style="display: none;">
                <div class="added-subjects-header" id="addedSubjectsHeader" title="Clic para ver o descargar el permiso de examen en una nueva pestaña" style="cursor: pointer;">
                    <div class="added-subjects-title">
                        <span class="material-symbols-outlined">inventory_2</span>
                        Materias seleccionadas:
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.6rem;">
                        <span class="subject-badge-count" id="subjectBadgeCount">0 cargada(s)</span>
                        <button type="button" id="btnOpenPdfFromCart" class="btn-pdf-quick-access" title="Descargar o imprimir permiso de examen">
                            <span class="material-symbols-outlined" style="font-size: 1.1rem;">print</span>
                            Descargar / Imprimir
                        </button>
                    </div>
                </div>
                <div id="addedSubjectsList"></div>
            </div>

            <div id="alertBox" class="alert-custom" style="display: none;"></div>
        </section>

        <!-- Vista previa del permiso de examen para descargar o imprimir -->
        <section id="pdfPreviewSection" class="pdf-preview-section">
            
            <!-- Aviso en pantalla (tiene la clase no-print para que no salga en la hoja impresa) -->
            <div class="alert-custom alert-success no-print" style="margin-bottom: 1.5rem;">
                <span class="material-symbols-outlined" style="vertical-align: middle; margin-right: 0.3rem;">check_circle</span>
                <strong>¡Preinscripción cargada exitosamente!</strong><br>
                Para completar la preinscripción debes descargar el permiso de examen en PDF que se muestra a continuación y presentarlo en la escuela junto con el importe de inscripción de <strong id="alertImporteInscripcion" style="color: #ffeb3b; text-decoration: underline;">$3.000</strong>.<br>
                <div style="margin-top: 0.5rem; padding: 0.4rem 0.6rem; background: rgba(0,0,0,0.25); border-radius: 6px; font-size: 0.88rem; color: #fff;">
                    <span class="material-symbols-outlined" style="vertical-align: middle; font-size: 1rem; color: #ffeb3b;">info</span>
                    <strong>Importante:</strong> La inscripción no está terminada hasta que se abone el valor en la institución.
                </div>
            </div>

            <!-- Botones de descarga e impresión -->
            <div class="pdf-actions-bar no-print" style="display: flex; flex-direction: column; gap: 0.9rem; margin-bottom: 1.5rem; align-items: center;">
                
                <button id="btnDownloadPdf" class="btn-ingresar" style="width: 100%; max-width: 440px; padding: 14px 28px; background: #ffeb3b; color: #7a0000; border-color: #f57c00;">
                    <span class="material-symbols-outlined">download</span> Descargar PDF (Permiso de Examen)
                </button>

                <div style="display: flex; gap: 0.8rem; width: 100%; max-width: 440px; justify-content: center;">
                    <button id="btnPrintPdf" class="btn-secondary-auth" style="flex: 1; margin-top: 0; padding: 12px 14px;">
                        <span class="material-symbols-outlined">print</span> Imprimir Permiso
                    </button>
                    <button id="btnReturnHome" class="btn-secondary-auth" style="flex: 1; margin-top: 0; padding: 12px 14px; background: rgba(255,255,255,0.18);">
                        <span class="material-symbols-outlined">home</span> Volver al Inicio
                    </button>
                </div>
            </div>

            <!-- Plantilla del permiso de examen en formato A4 -->
            <div id="pdfDocument" class="pdf-document">
                
                <div class="pdf-top-section">
                    <table class="pdf-header-table">
                        <tr>
                            <td style="width: 80px; text-align: left; vertical-align: middle;">
                                <img id="pdfEscudoImg" src="imagenes/escudo.png" style="width: 75px; height: auto;" alt="Escudo Entre Ríos">
                            </td>
                            <td class="pdf-header-title">
                                Provincia de Entre Ríos<br>
                                Consejo General de Educación<br>
                                Dirección de Educación Técnico Profesional<br><br>
                                <span class="pdf-main-title">SOLICITUD DE PERMISO DE EXAMEN</span>
                            </td>
                            <td style="width: 80px; text-align: right; vertical-align: middle;">
                                <img id="pdfLogoImg" src="imagenes/logo.png" style="width: 75px; height: auto;" alt="Logo Escuela">
                            </td>
                        </tr>
                    </table>

                    <div class="pdf-school-name">
                        ESTABLECIMIENTO: ESCUELA TÉCNICA N°2 "INDEPENDENCIA"- CONCORDIA – ENTRE RIOS
                    </div>
                    
                    <div class="pdf-section-label">DATOS DEL ALUMNO:</div>

                    <div class="pdf-student-box">
                        <div class="pdf-box-row">
                            <span class="pdf-box-label">Apellido y nombres:</span>
                            <span class="pdf-dotted-fill" id="pdfStudentName">........................................................................................................................................................</span>
                        </div>
                        <div class="pdf-box-row pdf-box-2col" style="margin-top: 6px;">
                            <div style="flex: 1; display: flex;">
                                <span class="pdf-box-label">D.N.I. N°:</span>
                                <span class="pdf-dotted-fill" id="pdfStudentDni">...................................................</span>
                            </div>
                            <div style="flex: 1; display: flex; margin-left: 15px;">
                                <span class="pdf-box-label">Tel:</span>
                                <span class="pdf-dotted-fill" id="pdfStudentPhone">...................................................</span>
                            </div>
                        </div>
                        <div class="pdf-box-row" style="margin-top: 6px;">
                            <span class="pdf-box-label">Lugar y fecha de nacimiento:</span>
                            <span class="pdf-dotted-fill" id="pdfStudentBirthdate">................................................................................................................................</span>
                        </div>
                        <div class="pdf-box-row pdf-box-2col" style="margin-top: 6px;">
                            <div style="flex: 1; display: flex;">
                                <span class="pdf-box-label">Libro matriz N°:</span>
                                <span class="pdf-dotted-fill">...................................................</span>
                            </div>
                            <div style="flex: 1; display: flex; margin-left: 15px;">
                                <span class="pdf-box-label">Folio N°:</span>
                                <span class="pdf-dotted-fill">...................................................</span>
                            </div>
                        </div>
                    </div>

                    <!-- Tabla de materias (parte para la escuela) -->
                    <table class="pdf-exam-table">
                        <thead>
                            <tr>
                                <th style="width: 32%;">Asignaturas</th>
                                <th style="width: 10%;">Curso</th>
                                <th style="width: 15%;">Año en<br>que cursó</th>
                                <th style="width: 15%;">Turno de<br>examen</th>
                                <th style="width: 12%;">Calificación</th>
                                <th style="width: 16%;">Firma del docente</th>
                            </tr>
                        </thead>
                        <tbody id="pdfTableBody1">
                        </tbody>
                    </table>

                    <div class="pdf-middle-lines">
                        <div class="pdf-line-row">
                            <span class="pdf-box-label">Establecimiento de procedencia:</span>
                            <span class="pdf-dotted-fill">...................................................................................................................................................</span>
                        </div>
                        <div class="pdf-line-row" style="margin-top: 6px;">
                            <span class="pdf-box-label">Firma del alumno:</span>
                            <span class="pdf-dotted-fill">............................................................................................................................................................</span>
                        </div>
                        <div class="pdf-line-row pdf-box-2col" style="margin-top: 6px;">
                            <div style="flex: 1.8; display: flex; align-items: baseline;">
                                <span class="pdf-box-label">Empleado que recepciona:</span>
                                <span class="pdf-dotted-fill">...................................................</span>
                            </div>
                            <div style="flex: 1; display: flex; align-items: baseline; margin-left: 15px; max-width: 200px;">
                                <span class="pdf-box-label">Fecha:</span>
                                <span class="pdf-dotted-fill">........................</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pdf-bottom-section">
                    <hr class="pdf-divider">

                    <!-- Talón inferior que le queda al alumno como comprobante -->
                    <div class="pdf-talon-title">TALON PARA EL ALUMNO:</div>

                    <table class="pdf-exam-table">
                        <thead>
                            <tr>
                                <th style="width: 32%;">Asignaturas</th>
                                <th style="width: 10%;">Curso</th>
                                <th style="width: 15%;">Año en<br>que cursó</th>
                                <th style="width: 15%;">Turno de<br>examen</th>
                                <th style="width: 12%;">Calificación</th>
                                <th style="width: 16%;">Firma del docente</th>
                            </tr>
                        </thead>
                        <tbody id="pdfTableBody2">
                        </tbody>
                    </table>

                    <div class="pdf-footer-notice">
                        PRESENTAR EN LA MESA DE EXAMEN JUNTO CON EL DNI. -
                    </div>
                    
                    <div class="pdf-footer-signature">
                        <span class="pdf-box-label">FIRMA DEL PADRE:</span>
                        <span class="pdf-dotted-fill">........................................................................................................................................................................</span>
                    </div>
                </div>

            </div>

        </section>

    </main>

    <!-- Footer con apartado de ayuda y video tutorial al pie de la página -->
    <footer class="app-page-footer no-print">
        <aside class="help-tutorial-banner" aria-label="Ayuda para alumnos">
            <div class="help-tutorial-content">
                <div class="help-tutorial-icon">
                    <span class="material-symbols-outlined">help</span>
                </div>
                <div class="help-tutorial-text">
                    <span class="help-tutorial-question">¿Tienes problemas?</span>
                    <span class="help-tutorial-cta">¡Aquí tienes ayuda! :D</span>
                </div>
            </div>
            <a href="https://youtu.be/Nle67X21eSg" target="_blank" rel="noopener noreferrer" class="help-tutorial-btn">
                <span class="material-symbols-outlined">play_circle</span>
                <span>Ver video tutorial</span>
            </a>
        </aside>
    </footer>

    <!-- Ventana que pregunta si quiere anotarse a otra materia más o terminar -->
    <div id="modalMultiSubject" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-icon">
                <span class="material-symbols-outlined" style="font-size: 3.5rem; color: var(--color-gold);">auto_stories</span>
            </div>
            <div class="modal-title">¿Deseas inscribirte a otra materia?</div>
            <div class="modal-body-text">
                La materia ha sido cargada correctamente a tu lista de preinscripción.<br><br>
                ¿Quieres agregar otra materia adeudada a esta solicitud o deseas finalizar y descargar tu permiso?
            </div>
            <div class="modal-actions">
                <button id="btnModalYes" class="btn-ingresar">
                    <span class="material-symbols-outlined">add</span> Sí, agregar otra
                </button>
                <button id="btnModalNo" class="btn-secondary-auth">
                    <span class="material-symbols-outlined">description</span> No, finalizar y descargar PDF
                </button>
            </div>
        </div>
    </div>

    <script src="src/js/assets.js"></script>
    <script src="src/js/core.js?v=<?= filemtime(__DIR__ . '/src/js/core.js') ?>"></script>
    <script src="src/js/alumnos.bundle.js?v=<?= filemtime(__DIR__ . '/src/js/alumnos.bundle.js') ?>"></script>
</body>
</html>
