-- =============================================================================
-- BASE DE DATOS: eet2_mesas
-- Sistema de Preinscripción a Mesas de Examen
-- E.E.T. N° 2 "Independencia" - Anexo de Formación Profesional
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `eet2_mesas` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `eet2_mesas`;

-- Creación y permisos de usuario para la aplicación:
CREATE USER IF NOT EXISTS 'mesast2'@'localhost' IDENTIFIED BY 'mesast2_clave';
GRANT ALL PRIVILEGES ON `eet2_mesas`.* TO 'mesast2'@'localhost';
FLUSH PRIVILEGES;

-- -----------------------------------------------------------------------------
-- 1. Tabla de Mesas de Examen Programadas por Directivos
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mesas_examen` (
    `id` VARCHAR(50) NOT NULL PRIMARY KEY,
    `subject_name` VARCHAR(150) NOT NULL,
    `course` VARCHAR(100) NOT NULL,
    `specialty` VARCHAR(50) DEFAULT NULL,
    `exam_turn` ENUM('Febrero / Marzo', 'Julio / Agosto', 'Noviembre / Diciembre') NOT NULL,
    `exam_date` DATE NOT NULL,
    `exam_time` VARCHAR(10) NOT NULL DEFAULT '08:00',
    `classroom` VARCHAR(100) NOT NULL DEFAULT 'Aula 1',
    `pres_docente` VARCHAR(120) NOT NULL,
    `v1_docente` VARCHAR(120) NOT NULL,
    `v2_docente` VARCHAR(120) NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. Tabla Principal de Preinscripciones de Alumnos
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alumnos_inscripciones` (
    `id` VARCHAR(50) NOT NULL PRIMARY KEY,
    `student_name` VARCHAR(150) NOT NULL,
    `student_dni` VARCHAR(20) NOT NULL,
    `student_course` VARCHAR(50) NOT NULL,
    `student_phone` VARCHAR(50) DEFAULT NULL,
    `student_email` VARCHAR(120) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `paid` TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. Tabla Relacional de Materias Solicitadas por Cada Alumno
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inscripcion_materias` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `inscripcion_id` VARCHAR(50) NOT NULL,
    `subject_name` VARCHAR(150) NOT NULL,
    `subject_year` VARCHAR(50) DEFAULT NULL,
    `subject_family` VARCHAR(100) DEFAULT NULL,
    `exam_turn` VARCHAR(50) NOT NULL,
    `paid` TINYINT(1) NOT NULL DEFAULT 0,
    `grade` INT DEFAULT NULL,
    CONSTRAINT `fk_inscripcion_alumno` FOREIGN KEY (`inscripcion_id`) 
        REFERENCES `alumnos_inscripciones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. Tabla de Configuración del Sistema (Período de Preinscripciones)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `configuracion` (
    `clave` VARCHAR(50) NOT NULL PRIMARY KEY,
    `valor` TEXT DEFAULT NULL,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Datos Iniciales de Ejemplo (Opcional)
-- -----------------------------------------------------------------------------
INSERT INTO `mesas_examen` (`id`, `subject_name`, `course`, `specialty`, `exam_turn`, `exam_date`, `exam_time`, `classroom`, `pres_docente`, `v1_docente`, `v2_docente`)
VALUES 
('MESA-1001', 'Laboratorio I', '6to TC', 'TC', 'Febrero / Marzo', '2026-02-26', '08:00', 'Laboratorio de Informática', 'Gómez Martín', 'López Valeria', 'Ruiz Carlos'),
('MESA-1002', 'Electrotecnia General', '4to TAE', 'TAE', 'Febrero / Marzo', '2026-02-27', '09:30', 'Taller de Electricidad', 'Benítez Javier', 'Fernández Diego', 'Díaz Marcela'),
('MESA-1003', 'Química Aplicada II', '5to TG', 'TG', 'Julio / Agosto', '2026-07-15', '14:00', 'Taller de Gastronomía', 'Rossi Andrea', 'Blanco Pablo', 'Suárez Norma')
ON DUPLICATE KEY UPDATE `id`=`id`;

INSERT INTO `configuracion` (`clave`, `valor`) VALUES
('preinscripcion_habilitada', '1'),
('preinscripcion_fecha_inicio', '2026-01-01'),
('preinscripcion_fecha_fin', '2026-12-31')
ON DUPLICATE KEY UPDATE `clave`=`clave`;
