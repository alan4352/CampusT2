<?php
/* =====================================================================
   ARCHIVO: index.php  (PÁGINA PRINCIPAL / LANDING PAGE)
   PARA QUÉ SIRVE: es lo primero que ve el visitante. Es pública:
   no requiere iniciar sesión.
   Incluimos sesion.php para saber si el visitante ya está logueado
   y así cambiar el botón "Ingresar al campus" por "Ir a mi panel".
   ===================================================================== */
require_once 'includes/sesion.php';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- AQUÍ SE CAMBIA EL NOMBRE DE LA ESCUELA (título de la pestaña del navegador) -->
    <title>Escuela Técnica — Inicio</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>

    <!-- =================== BARRA DE NAVEGACIÓN =================== -->
    <header class="barra">
        <div class="contenedor barra__interior">
            <!-- AQUÍ VA EL LOGO Y EL NOMBRE CORTO DE LA ESCUELA
             - LOGO: guarde su imagen como img/logo.png (si usa otro nombre o formato, cámbielo en src="...")
             - NOMBRE: cambie el texto "Escuela Técnica" por el nombre corto de su escuela
             - El onerror oculta la imagen mientras todavía no exista el archivo -->
            <a href="index.php" class="barra__logo">
                <img src="img/logo.png" alt="Logo de la escuela" class="logo" onerror="this.style.display='none'">
                Escuela Técnica
            </a>

            <nav class="barra__menu">
                <a href="#ciclo-basico">Ciclo Básico</a>
                <a href="#cuarto-anio">Tecnicaturas</a>
                <a href="#formacion-profesional">Formación Profesional</a>
                <a href="#contacto">Contacto</a>

                <?php if (estaLogueado()): ?>
                    <!-- Si ya inició sesión, mostramos acceso directo al panel -->
                    <a href="dashboard.php" class="boton boton--chico">Ir a mi panel</a>
                <?php else: ?>
                    <!-- Si NO inició sesión, mostramos el botón de ingreso -->
                    <a href="login.php" class="boton boton--chico">Ingresar al campus</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>


    <!-- =================== ENCABEZADO (HERO) =================== -->
    <section class="hero">
        <div class="contenedor hero__interior">
            <div class="hero__texto">
                <span style="display: inline-block; background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 12px;">
                    Educación Técnico Profesional · Pública y Gratuita
                </span>
                <h1>E.E.T. N° 2 "Independencia"</h1>
                <p>
                    Bienvenidos al espacio virtual de la E.E.T. N° 2 "Independencia", una institución de nivel secundario y formación profesional consolidada como pilar fundamental para el desarrollo educativo y socioproductivo de la ciudad de Concordia, Entre Ríos.
                </p>
                <p>
                    Nuestra propuesta educativa integra la teoría con la práctica en talleres reales, promoviendo el pensamiento crítico, la innovación tecnológica y la empleabilidad de jóvenes y adultos.
                </p>
                <div style="display: flex; gap: 14px; flex-wrap: wrap; margin-top: 10px;">
                    <a href="#cuarto-anio" class="boton">Nuestras Tecnicaturas</a>
                    <a href="#formacion-profesional" class="boton boton--borde">Talleres de Formación</a>
                </div>
            </div>

            <div class="hero__foto">
                <img src="img/Imagen_frente_escuela.webp" alt="Fachada de la E.E.T. N° 2 Independencia">
            </div>
        </div>
    </section>


    <main>


        <!-- =================== SECCIÓN: CICLO BÁSICO =================== -->
        <section id="ciclo-basico" class="seccion">
            <div class="contenedor">
                <h2>Ciclo Básico (1.º a 3.er año)</h2>
                <p class="seccion__intro">
                    El Ciclo Básico tiene una duración de tres años y se enfoca en la <strong>«Vinculación con el Mundo del Trabajo y la Producción»</strong>. Mediante un sistema de talleres rotativos, los alumnos ingresantes tienen su primer contacto práctico con herramientas y lógicas de nuestras tres grandes especialidades:
                </p>

                <div class="tarjetas">
                    <article class="tarjeta">
                        <span class="tarjeta__icono"></span>
                        <h3>Taller de Gastronomía</h3>
                        <p>Prácticas en entornos de cocina reales, aprendiendo normas de higiene, seguridad bromatológica y elaboración de preparaciones básicas.</p>
                    </article>
                    <article class="tarjeta">
                        <span class="tarjeta__icono"></span>
                        <h3>Taller de Computación</h3>
                        <p>Desarrollo del pensamiento computacional, algoritmos, mantenimiento de equipos, internet segura y uso de herramientas digitales.</p>
                    </article>
                    <article class="tarjeta">
                        <span class="tarjeta__icono"></span>
                        <h3>Organización Empresarial</h3>
                        <p>Primeros pasos en la gestión de empresas, cálculo de costos, presupuestos, nociones comerciales y mentalidad emprendedora.</p>
                    </article>
                </div>
            </div>
        </section>


        <!-- =================== SECCIÓN: 4TO AÑO TECNICATURAS =================== -->
        <section id="cuarto-anio" class="seccion seccion--gris">
            <div class="contenedor">
                <h2>4.º a 7.º Año: Tecnicaturas de Nivel Secundario</h2>
                <p class="seccion__intro">
                    Al culminar el Ciclo Básico, el estudiante elige su orientación técnica profesional. Son trayectos formativos de excelencia orientados a las demandas laborales del sector productivo actual:
                </p>

                <div class="aviso" style="margin-bottom: 24px;">
                     <strong>Aviso curricular:</strong> a partir de 4.º año la materia «Organización Empresarial» evoluciona hacia <strong>«Administración Empresarial»</strong> con contenidos avanzados de gestión y finanzas.
                </div>

                <div class="tarjetas--tecnicaturas">
                    
                    <!-- TECNICATURA 1: COMPUTACIÓN -->
                    <article class="tarjeta--tecnicatura">
                        <div class="tarjeta__cabecera">
                            
                            <h3> Técnico en Computación</h3>
                        </div>
                        <div class="tarjeta__cuerpo">
                            <p>
                                Especialidad de vanguardia enfocada en la lógica computacional, desarrollo de software y administración de infraestructura digital. Prepara profesionales capaces de liderar la transformación tecnológica.
                            </p>
                            <ul class="tarjeta__lista">
                                <li>Programación y desarrollo de aplicaciones web</li>
                                <li>Diseño y administración de bases de datos</li>
                                <li>Configuración de redes informáticas y seguridad</li>
                                <li>Soporte técnico, hardware y mantenimiento</li>
                            </ul>
                        </div>
                    </article>

                    <!-- TECNICATURA 2: ADMINISTRACIÓN -->
                    <article class="tarjeta--tecnicatura">
                        <div class="tarjeta__cabecera">
                            
                            <h3>Técnico en Administración de Empresas</h3>
                        </div>
                        <div class="tarjeta__cuerpo">
                            <p>
                                Brinda sólidos conocimientos en contabilidad financiera, control de gestión, legislación comercial y marketing. Forma egresados con aptitudes para incorporarse a PyMEs o gestionar proyectos propios.
                            </p>
                            <ul class="tarjeta__lista">
                                <li>Contabilidad financiera y balances</li>
                                <li>Legislación laboral, impositiva y comercial</li>
                                <li>Estrategias de marketing y costos de producción</li>
                                <li>Planificación y gestión de microemprendimientos</li>
                            </ul>
                        </div>
                    </article>

                    <!-- TECNICATURA 3: GASTRONOMÍA -->
                    <article class="tarjeta--tecnicatura">
                        <div class="tarjeta__cabecera">
                            
                            <h3> Técnico en Gastronomía</h3>
                        </div>
                        <div class="tarjeta__cuerpo">
                            <p>
                                Trasciende la cocina básica para abarcar la gestión integral de empresas de alimentos y bebidas, nutrición, diseño de menús y altos estándares de hospitalidad vinculados al turismo regional.
                            </p>
                            <ul class="tarjeta__lista">
                                <li>Técnicas culinarias avanzadas y cocina regional</li>
                                <li>Bromatología y seguridad alimentaria (BPM / HACCP)</li>
                                <li>Costos y administración de restaurantes y eventos</li>
                                <li>Nutrición, servicio de salón y maridaje</li>
                            </ul>
                        </div>
                    </article>

                </div>
            </div>
        </section>


        <!-- =================== SECCIÓN: FORMACIÓN PROFESIONAL =================== -->
        <section id="formacion-profesional" class="seccion">
            <div class="contenedor">
                <h2>Formación Profesional (Cursos y Oficios)</h2>
                <p class="seccion__intro">
                    Capacitación laboral práctica y dinámica pensada para jóvenes y adultos que buscan aprender un oficio con salida directa o perfeccionar sus habilidades.
                </p>

                <!-- Destacado con imagen institucional de FP -->
                <div class="fp-destacado">
                    <div class="fp-destacado__texto">
                        <h3>Aprender haciendo, sin límite de edad</h3>
                        <p>
                            El Anexo de Formación Profesional brinda un entorno cordial de aprendizaje práctico en talleres adaptados. Aquí la teoría se comprueba día a día en el puesto de trabajo.
                        </p>
                        <p style="margin-top: 14px; font-weight: 600; color: var(--secondary);">
                            📌 Para más información por las inscripciones, por favor comuníquese con la escuela.
                        </p>
                    </div>
                    <div>
                        <img src="img/Imagen_fp.webp" alt="Alumnos en el taller de Formación Profesional" class="fp-destacado__foto">
                    </div>
                </div>

                <!-- Aviso de inscripciones -->
                <div class="aviso" style="margin-bottom: 24px;">
                    <strong>ℹ️ Inscripciones a Formación Profesional:</strong> Para más información sobre requisitos, fechas y vacantes de inscripción, por favor comuníquese con la escuela al <a href="tel:03454213434" style="color: var(--primary); font-weight: 600;">0345 421-3434</a> o vía correo a <a href="https://mail.google.com/mail/?view=cm&fs=1&to=eet2.cd@entrerios.edu.ar" target="_blank" rel="noopener noreferrer" style="color: var(--primary); font-weight: 600; text-decoration: underline;">eet2.cd@entrerios.edu.ar</a>. <a href="#contacto" style="font-weight: 600; text-decoration: underline; margin-left: 6px;">Ver ubicación y contacto →</a>
                </div>

                <!-- TALLERES DESPLEGABLES (ACORDEÓN INTERACTIVO) -->
                <div class="talleres-grid">

                    <!-- 1. PANADERÍA -->
                    <details class="taller-desplegable">
                        <summary class="taller-desplegable__resumen">
                            <span class="taller-desplegable__titulo">
                                <span class="taller-desplegable__icono"></span>
                                <h3>Panadería</h3>
                            </span>
                            
                        </summary>
                        <div class="taller-desplegable__contenido">
                            <div class="taller-desplegable__bloque">
                                <h4>Mini Teoría del Oficio</h4>
                                <p>
                                    La panadería combina ciencia y técnica artesanal: comprende la química de la fermentación biológica (acción de las levaduras sobre los azúcares de la harina), el desarrollo de la malla de gluten mediante el amasado, los tiempos de reposo (leudado) y el control preciso de temperatura y humedad en el horno.
                                </p>
                            </div>
                        </div>
                    </details>

                    <!-- 2. PASTELERÍA -->
                    <details class="taller-desplegable">
                        <summary class="taller-desplegable__resumen">
                            <span class="taller-desplegable__titulo">
                                <span class="taller-desplegable__icono"></span>
                                <h3>Pastelería</h3>
                            </span>
                           
                        </summary>
                        <div class="taller-desplegable__contenido">
                            <div class="taller-desplegable__bloque">
                                <h4> Mini Teoría del Oficio</h4>
                                <p>
                                    La pastelería es el arte de la precisión milimétrica: se estudian los batidos livianos (bizcochuelos aireados) y pesados (budines y masas densas), la química del azúcar en almíbares, emulsiones de cremas, templado de chocolate y el comportamiento del hojaldre por laminación de materias grasas.
                                </p>
                            </div>
                        </div>
                    </details>

                    <!-- 3. COCINA -->
                    <details class="taller-desplegable">
                        <summary class="taller-desplegable__resumen">
                            <span class="taller-desplegable__titulo">
                                <span class="taller-desplegable__icono"></span>
                                <h3>Cocina</h3>
                            </span>
                            
                        </summary>
                        <div class="taller-desplegable__contenido">
                            <div class="taller-desplegable__bloque">
                                <h4> Mini Teoría del Oficio</h4>
                                <p>
                                    La cocina es el arte de la combinación de sabores y texturas: se estudian los métodos de cocción (a fuego, al vapor, en sartén), la manipulación de ingredientes (corte, preparación, conservación), y la creación de platos equilibrados y visualmente atractivos.
                                </p>
                            </div>
                        </div>
                    </details>

                    <!-- 4. IMPRENTA -->
                    <details class="taller-desplegable">
                        <summary class="taller-desplegable__resumen">
                            <span class="taller-desplegable__titulo">
                                <span class="taller-desplegable__icono"></span>
                                <h3>Imprenta</h3>
                            </span>
                           
                        </summary>
                        <div class="taller-desplegable__contenido">
                            <div class="taller-desplegable__bloque">
                                <h4> Mini Teoría del Oficio</h4>
                                <p>
                                    Comprende los procesos de la comunicación visual impresa: preprensa, matrices permeográficas (serigrafía) y tipográficas, física de la transferencia de tinta a sustratos, tipos de papel (gramaje, textura, absorción) y encuadernación editorial.
                                </p>
                            </div>
                        </div>
                    </details>

                    <!-- 5. VITROFUSIÓN -->
                    <details class="taller-desplegable">
                        <summary class="taller-desplegable__resumen">
                            <span class="taller-desplegable__titulo">
                                <span class="taller-desplegable__icono"></span>
                                <h3>Vitrofusión</h3>
                            </span>
                            
                        </summary>
                        <div class="taller-desplegable__contenido">
                            <div class="taller-desplegable__bloque">
                                <h4> Mini Teoría del Oficio</h4>
                                <p>
                                    La vitrofusión es la técnica artesanal de superposición y fundición térmica del vidrio a temperaturas entre 700°C y 850°C. Requiere comprender los coeficientes de dilatación (compatibilidad de vidrios), curvas de horneado y etapas de recocido para evitar tensiones mecánicas y roturas por choque térmico.
                                </p>
                            </div>
                        </div>
                    </details>

                    <!-- 6. TELAR -->
                    <details class="taller-desplegable">
                        <summary class="taller-desplegable__resumen">
                            <span class="taller-desplegable__titulo">
                                <span class="taller-desplegable__icono"></span>
                                <h3>telar</h3>
                            </span>
                           
                        </summary>
                        <div class="taller-desplegable__contenido">
                            <div class="taller-desplegable__bloque">
                                <h4> Mini Teoría del Oficio</h4>
                                <p>
                                    El tejido en telar es una de las tecnologías milenarias de la humanidad: se basa en el entrelazado perpendicular de hilos longitudinales (urdimbre) y transversales (trama). Se estudian las propiedades térmicas de fibras naturales (oveja, llama, algodón), resistencia a la tracción y armonía cromática.
                                </p>
                            </div>
                        </div>
                    </details>

                    <!-- 7. MODISTA -->
                    <details class="taller-desplegable">
                        <summary class="taller-desplegable__resumen">
                            <span class="taller-desplegable__titulo">
                                <span class="taller-desplegable__icono"></span>
                                <h3>Confeccionista a medida</h3>
                            </span>
                           
                        </summary>
                        <div class="taller-desplegable__contenido">
                            <div class="taller-desplegable__bloque">
                                <h4>Mini Teoría del Oficio</h4>
                                <p>
                                    El patronaje y la confección indumentaria integran geometría corporal y costura técnica: toma exacta de medidas anatómicas, trazado de moldería base y progresiones, comportamiento de los tejidos al bies y al hilo, y cálculo de holguras.
                                </p>
                            
                            </div>
                        </div>
                    </details>

                    <!-- 8. PRODUCTOR DE OBJETOS DECORATIVOS -->
                    <details class="taller-desplegable">
                        <summary class="taller-desplegable__resumen">
                            <span class="taller-desplegable__titulo">
                                <span class="taller-desplegable__icono"></span>
                                <h3>Productor de objetos decorativos</h3>
                            </span>
                           
                        </summary>
                        <div class="taller-desplegable__contenido">
                            <div class="taller-desplegable__bloque">
                                <h4>Mini Teoría del Oficio</h4>
                                <p>
                                    La producción de objetos decorativos integra el diseño formal y el dominio de diversas técnicas artesanales: abarca el modelado, ensamble y transformación de materiales (madera, yeso, cerámica en frío, cartapesta y reciclados), el estudio de la teoría del color, volumen y proporciones, y la aplicación de acabados superficiales como pátinas, decapados, barnices y texturados para ambientaciones funcionales y estéticas.
                                </p>
                            
                            </div>
                        </div>
                    </details>

                </div>
            </div>
        </section>


        <!-- =================== SECCIÓN: CONTACTO Y UBICACIÓN =================== -->
        <section id="contacto" class="seccion seccion--gris">
            <div class="contenedor">
                <h2>Información Institucional y Ubicación</h2>
                <p class="seccion__intro">
                    La E.E.T. N° 2 "Independencia" se encuentra abierta a toda la comunidad de Concordia.
                </p>

                <div class="contacto-grid">
                    <div class="contacto-col">
                        <!-- TARJETA: CONTACTO -->
                        <article class="contacto-item">
                            <h3>📞 Contacto</h3>
                            <p>Comuníquese con nuestra institución para inscripciones, consultas académicas o administrativas:</p>
                            <ul class="contacto-lista">
                                <li>
                                    <strong>Contacto:</strong>
                                    <a href="tel:03454213434">0345 421-3434</a>
                                </li>
                                <li>
                                    <strong>Correo:</strong>
                                    <a href="https://mail.google.com/mail/?view=cm&fs=1&to=eet2.cd@entrerios.edu.ar" target="_blank" rel="noopener noreferrer">eet2.cd@entrerios.edu.ar</a>
                                </li>
                            </ul>
                        </article>

                        <!-- TARJETA: CAMPUS VIRTUAL -->
                        <article class="contacto-item">
                            <h3>💻 Campus Virtual</h3>
                            <p>Plataforma para reservas, consultas de programas de examen y gestión académica para alumnos y docentes.</p>
                            <p style="margin-top: 14px;"><a href="login.php" class="boton boton--chico">Acceder al Campus →</a></p>
                        </article>
                    </div>

                    <!-- TARJETA: UBICACIÓN Y MAPA -->
                    <article class="contacto-item contacto-item--mapa">
                        <h3>📍 Ubicación</h3>
                        <p><strong>Concordia, Entre Ríos</strong><br>Dependiente del Consejo General de Educación (CGE).</p>
                        <p style="font-size: 0.9rem; color: #6b7280; margin-top: 6px;">Consulte en el mapa interactivo para ver dónde estamos ubicados:</p>
                        <div class="mapa-contenedor">
                            <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3405.6327363738574!2d-58.01649612435255!3d-31.396688774270658!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x95ade810ca7ee62d%3A0x26431b08edafcb19!2sEscuela%20T%C3%A9cnica%20N%C2%B02%20Independencia!5e0!3m2!1ses-419!2sar!4v1793703968432!5m2!1ses-419!2sar" width="400" height="300" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>
                        </div>
                    </article>
                </div>
            </div>
        </section>

    </main>


    <!-- =================== PIE DE PÁGINA =================== -->
    <footer class="pie">
        <div class="contenedor">
            <p>© <?php echo date('Y'); // date('Y') imprime el año actual automáticamente 
                    ?> Escuela de Educación Técnica N.º 2 · Todos los derechos reservados</p>
            <p style="font-size: 0.85rem; margin-top: 6px; opacity: 0.85;">
                Contacto: <strong>0345 421-3434</strong> · Correo: <a href="https://mail.google.com/mail/?view=cm&fs=1&to=eet2.cd@entrerios.edu.ar" target="_blank" rel="noopener noreferrer" style="color: inherit; text-decoration: underline;">eet2.cd@entrerios.edu.ar</a>
            </p>
        </div>
    </footer>

</body>

</html>