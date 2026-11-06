// Lógica del panel de directivos: habilitar fechas, armar mesas, registrar pagos, sacar actas y cargar notas.
(function() {
    "use strict";
    const {
        listado_materias,
        getCurrentExamTurn,
        DB_KEY,
        MESAS_KEY,
        CONFIG_KEY,
        DB,
        highlightFieldError,
        clearFieldErrors,
        COURSE_LEVEL_MAP,
        YEAR_LEVEL_MAP,
        initAllCustomSelects,
        esc
    } = window.AppCore;

    // Variables para controlar si hay cambios sin guardar o si se está editando una mesa existente
    let savedHabilitarState = null;
    let editingMesaId = null;
    let selectedMesaCourses = [];
    let selectedMesaSubjects = [];

    function getHabilitarFormState() {
        const toggle = document.getElementById('configEnableToggle');
        const startInput = document.getElementById('configFechaInicio');
        const endInput = document.getElementById('configFechaFin');
        const importeInput = document.getElementById('configImporteInscripcion');
        return {
            enabled: toggle ? toggle.checked : false,
            start: startInput ? startInput.value : '',
            end: endInput ? endInput.value : '',
            importe: importeInput ? importeInput.value : ''
        };
    }

    function showInstitutionalConfirm(title, message, acceptText, cancelText, callback) {
        const modal = document.getElementById('modalConfirmLeave');
        if (!modal) {
            const res = window.confirm(`${title}\n\n${message}`);
            callback(res);
            return;
        }
        const titleEl = document.getElementById('confirmLeaveTitle');
        const msgEl = document.getElementById('confirmLeaveMessage');
        const btnAccept = document.getElementById('btnConfirmLeaveAccept');
        const btnCancel = document.getElementById('btnConfirmLeaveCancel');

        if (titleEl) titleEl.textContent = title;
        if (msgEl) msgEl.textContent = message;
        if (btnAccept) {
            btnAccept.style.color = '#ffffff';
            btnAccept.innerHTML = `<span class="material-symbols-outlined" style="color: #ffffff; vertical-align: middle;">logout</span> <span style="color: #ffffff; font-weight: 700;">${acceptText || 'Salir'}</span>`;
        }
        if (btnCancel) {
            btnCancel.innerHTML = `<span class="material-symbols-outlined">close</span> ${cancelText || 'Permanecer aquí'}`;
        }

        modal.classList.add('active');
        modal.style.display = 'flex';

        function cleanUp() {
            modal.classList.remove('active');
            modal.style.display = 'none';
            btnAccept.removeEventListener('click', onAccept);
            btnCancel.removeEventListener('click', onCancel);
        }

        function onAccept() {
            cleanUp();
            callback(true);
        }
        function onCancel() {
            cleanUp();
            callback(false);
        }

        btnAccept.addEventListener('click', onAccept);
        btnCancel.addEventListener('click', onCancel);
    }

    function canLeaveHabilitarTab(callback) {
        const habilitarTab = document.getElementById('tabHabilitar');
        const directivosSection = document.getElementById('directivosSection');
        if (!habilitarTab || !habilitarTab.classList.contains('active') || (directivosSection && directivosSection.style.display === 'none')) {
            callback();
            return;
        }

        const current = getHabilitarFormState();
        const hasUnsavedChanges = !savedHabilitarState || (
            current.enabled !== savedHabilitarState.enabled ||
            current.start !== savedHabilitarState.start ||
            current.end !== savedHabilitarState.end ||
            current.importe !== savedHabilitarState.importe
        );

        if (hasUnsavedChanges) {
            showInstitutionalConfirm(
                "¿Desea salir sin guardar la configuración?",
                "Has realizado cambios en la configuración del período de preinscripción y no has guardado. Si sales ahora, se perderán las modificaciones.",
                "Salir sin guardar",
                "Permanecer aquí",
                (confirmed) => {
                    if (confirmed) {
                        if (!current.enabled) {
                            showInstitutionalConfirm(
                                "¿Desea salir sin habilitar la preinscripción de alumnos?",
                                "La preinscripción de alumnos se encuentra actualmente desactivada. Los alumnos permanecerán sin acceso al formulario.",
                                "Salir sin habilitar",
                                "Volver y habilitar",
                                (secondConfirmed) => {
                                    if (secondConfirmed) {
                                        callback();
                                    }
                                }
                            );
                        } else {
                            callback();
                        }
                    }
                }
            );
            return;
        }

        if (!current.enabled) {
            showInstitutionalConfirm(
                "¿Desea salir sin habilitar la preinscripción de alumnos?",
                "La preinscripción de alumnos se encuentra actualmente desactivada. Los alumnos permanecerán sin acceso al formulario.",
                "Salir sin habilitar",
                "Volver y habilitar",
                (confirmed) => {
                    if (confirmed) {
                        callback();
                    }
                }
            );
            return;
        }

        callback();
    }

    function isMesaFormDirty() {
        const mesasTab = document.getElementById('tabMesas');
        const directivosSection = document.getElementById('directivosSection');
        if (!mesasTab || !mesasTab.classList.contains('active') || (directivosSection && directivosSection.style.display === 'none')) {
            return false;
        }
        if (editingMesaId !== null) return true;
        if (selectedMesaCourses && selectedMesaCourses.length > 0) return true;
        if (selectedMesaSubjects && selectedMesaSubjects.length > 0) return true;
        const classroom = document.getElementById('mesaClassroomInput')?.value.trim() || '';
        const pres = document.getElementById('mesaPresDocente')?.value.trim() || '';
        const voc1 = document.getElementById('mesaVocal1Docente')?.value.trim() || '';
        const voc2 = document.getElementById('mesaVocal2Docente')?.value.trim() || '';
        const specialty = document.getElementById('mesaSpecialtySelect')?.value || '';
        if (classroom !== '' || pres !== '' || voc1 !== '' || voc2 !== '' || specialty !== '') {
            return true;
        }
        return false;
    }

    function canLeaveMesasTab(callback) {
        if (!isMesaFormDirty()) {
            callback();
            return;
        }
        showInstitutionalConfirm(
            "¿Desea salir sin guardar los cambios?",
            "Has realizado modificaciones en el formulario de creación de mesas y no has guardado. Si sales ahora, se perderán las modificaciones.",
            "Salir sin guardar",
            "Permanecer aquí",
            (confirmed) => {
                if (confirmed) {
                    if (typeof cancelEditMesa === 'function') {
                        cancelEditMesa();
                    }
                    callback();
                }
            }
        );
    }

    function canLeaveActiveDirectivosTab(callback) {
        canLeaveHabilitarTab(() => {
            canLeaveMesasTab(callback);
        });
    }

    // Advertencia en recarga o cierre de ventana si hay cambios pendientes
    window.addEventListener('beforeunload', (e) => {
        const habilitarTab = document.getElementById('tabHabilitar');
        const directivosSection = document.getElementById('directivosSection');
        if (habilitarTab && habilitarTab.classList.contains('active') && directivosSection && directivosSection.style.display !== 'none') {
            const current = getHabilitarFormState();
            const hasUnsavedChanges = !savedHabilitarState || (
                current.enabled !== savedHabilitarState.enabled ||
                current.start !== savedHabilitarState.start ||
                current.end !== savedHabilitarState.end ||
                current.importe !== savedHabilitarState.importe
            );
            if (hasUnsavedChanges) {
                e.preventDefault();
                e.returnValue = "¿Desea salir sin guardar la configuración?";
                return e.returnValue;
            }
        }
        if (isMesaFormDirty()) {
            e.preventDefault();
            e.returnValue = "¿Desea salir sin guardar los cambios?";
            return e.returnValue;
        }
    });

    function initDirectivosModule() {
        initDirectivosTabs();
        initDirectivosEvents();
        refreshDirectivosDashboard();
    }

    function initDirectivosTabs() {
        const tabButtons = document.querySelectorAll('.dir-tab-btn');
        const tabContents = document.querySelectorAll('.dir-tab-content');

        tabButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const targetTab = btn.getAttribute('data-tab');
                const activeBtn = document.querySelector('.dir-tab-btn.active');
                if (activeBtn && activeBtn.getAttribute('data-tab') === targetTab) return;

                canLeaveActiveDirectivosTab(() => {
                    tabButtons.forEach(b => b.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));

                    btn.classList.add('active');
                    const targetEl = document.getElementById(targetTab);
                    if (targetEl) targetEl.classList.add('active');

                    if (targetTab === 'tabStats') {
                        DB.syncWithServer();
                        renderStatsTab();
                    }
                    if (targetTab === 'tabMesas') {
                        DB.syncWithServer();
                        renderMesasTab();
                        updateMesaCourseChipsUI();
                        updateMesaSubjectOptions();
                    }
                    if (targetTab === 'tabAbonos') {
                        DB.syncWithServer();
                        populateAbonoFilters();
                        renderAbonosTab();
                    }
                    if (targetTab === 'tabListados') {
                        DB.syncWithServer();
                        renderListadosTab();
                    }
                    if (targetTab === 'tabNotas') {
                        DB.syncWithServer();
                        populateNotaFilters();
                        renderNotasTab();
                    }
                    if (targetTab === 'tabHabilitar') renderHabilitarTab();
                });
            });
        });

        initDirectivosNavArrows();
    }

    // Desplazamiento paso a paso entre opciones mediante flechas sin entrar a la pestaña
    function initDirectivosNavArrows() {
        const btnPrev = document.getElementById('btnNavPrev');
        const btnNext = document.getElementById('btnNavNext');
        const nav = document.getElementById('dirSidebarNav');
        if (!btnPrev || !btnNext || !nav) return;

        const buttons = Array.from(nav.querySelectorAll('.dir-tab-btn'));
        if (!buttons.length) return;

        let targetIndex = buttons.findIndex(b => b.classList.contains('active'));
        if (targetIndex < 0) targetIndex = 0;

        function updateArrowStates() {
            if (targetIndex <= 0) {
                btnPrev.disabled = true;
                btnPrev.classList.add('disabled');
            } else {
                btnPrev.disabled = false;
                btnPrev.classList.remove('disabled');
            }

            if (targetIndex >= buttons.length - 1) {
                btnNext.disabled = true;
                btnNext.classList.add('disabled');
            } else {
                btnNext.disabled = false;
                btnNext.classList.remove('disabled');
            }
        }

        function goToOption(index) {
            if (index < 0) index = 0;
            if (index >= buttons.length) index = buttons.length - 1;
            if (index === targetIndex) return;

            canLeaveActiveDirectivosTab(() => {
                targetIndex = index;
                const targetBtn = buttons[targetIndex];
                if (targetBtn) {
                    targetBtn.click();
                    const navRect = nav.getBoundingClientRect();
                    const btnRect = targetBtn.getBoundingClientRect();
                    const delta = (btnRect.left + btnRect.width / 2) - (navRect.left + navRect.width / 2);
                    const targetScrollLeft = Math.max(0, nav.scrollLeft + delta);
                    nav.scrollTo({
                        left: targetScrollLeft,
                        behavior: 'auto'
                    });
                    nav.scrollLeft = targetScrollLeft;
                }
                updateArrowStates();
            });
        }

        btnNext.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (targetIndex < buttons.length - 1) {
                goToOption(targetIndex + 1);
            }
        });

        btnPrev.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (targetIndex > 0) {
                goToOption(targetIndex - 1);
            }
        });

        buttons.forEach((btn, idx) => {
            btn.addEventListener('click', () => {
                targetIndex = idx;
                goToOption(idx);
            });
        });

        nav.addEventListener('scroll', () => {
            const navRect = nav.getBoundingClientRect();
            const navCenter = navRect.left + (navRect.width / 2);
            let closestIndex = 0;
            let minDiff = Infinity;
            buttons.forEach((btn, idx) => {
                const btnRect = btn.getBoundingClientRect();
                const btnCenter = btnRect.left + (btnRect.width / 2);
                const diff = Math.abs(btnCenter - navCenter);
                if (diff < minDiff) {
                    minDiff = diff;
                    closestIndex = idx;
                }
            });
            targetIndex = closestIndex;
            updateArrowStates();
        }, { passive: true });

        updateArrowStates();
    }

    function normalizeSubjectName(str) {
        if (!str) return '';
        return str
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[.:,]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function isNumberedSubject(subjectName) {
        if (!subjectName) return false;
        return /\s+(i{1,3}|iv|v|vi{0,3})$/i.test(subjectName.trim());
    }

    function getSubjectRomanNumeral(subjectName) {
        if (!subjectName) return null;
        const match = subjectName.trim().match(/\s+(i{1,3}|iv|v|vi{0,3})$/i);
        return match ? match[1].toUpperCase() : null;
    }

    function getRomanNumeralOrder(subjectName) {
        const rom = getSubjectRomanNumeral(subjectName);
        if (!rom) return 0;
        const orderMap = { 'I': 1, 'II': 2, 'III': 3, 'IV': 4, 'V': 5, 'VI': 6, 'VII': 7, 'VIII': 8 };
        return orderMap[rom] || 99;
    }

    function getSubjectFamily(subjectName) {
        if (!subjectName) return null;
        const norm = normalizeSubjectName(subjectName);
        if (norm.startsWith('lengua extranjera')) return null;
        if (norm.startsWith('ste ')) return null;
        if (norm === 'matematica tecnica') return null;

        const romanRegex = /\s+(i{1,3}|iv|v|vi{0,3})$/i;
        if (romanRegex.test(subjectName.trim())) {
            const base = subjectName.trim().replace(romanRegex, '').trim();
            const normBase = normalizeSubjectName(base);
            if (normBase.includes('ingles tecnico')) return 'Inglés Técnico';
            if (normBase.includes('laboratorio')) return 'Laboratorio';
            if (normBase.includes('programacion')) return 'Programación';
            if (normBase.includes('contabilidad')) return 'Contabilidad';
            if (normBase.includes('matematica')) return 'Matemática';
            if (normBase.includes('computacion')) return 'Computación';
            if (normBase.includes('org de la prod')) return 'Org. de la Producción';
            if (normBase.includes('sist proc de datos')) return 'Sistemas Proc. de Datos';
            if (normBase.includes('sist adm contable')) return 'Sist. Adm. Contable';
            if (normBase.includes('estudio del prod')) return 'Estudio del Producto';
            if (normBase.includes('quimica aplicada')) return 'Química Aplicada';
            return base;
        }

        if (norm === 'matematica') return 'Matemática';
        if (norm === 'contabilidad') return 'Contabilidad';
        return null;
    }

    function getPlanSubjectsForCourse(courseCode, specialty) {
        let key = courseCode;
        if (courseCode === '1ro' || courseCode === '1er' || courseCode === '1er AÑO') key = '1er AÑO';
        else if (courseCode === '2do' || courseCode === '2do AÑO') key = '2do AÑO';
        else if (courseCode === '3ro' || courseCode === '3er' || courseCode === '3er AÑO') key = '3er AÑO';
        else if (['4to', '5to', '6to', '7mo'].includes(courseCode)) {
            if (!specialty) return [];
            key = `${courseCode} ${specialty}`;
        }
        return listado_materias[key] || [];
    }

    function courseHasSubjectInPlan(courseCode, specialty, subjectName) {
        if (!courseCode || !subjectName) return false;
        const targetNorm = normalizeSubjectName(subjectName);
        const planSubs = getPlanSubjectsForCourse(courseCode, specialty);
        return planSubs.some(s => normalizeSubjectName(s) === targetNorm);
    }

    // Releva todos los cursos, tecnicaturas y materias que tienen al menos un alumno inscripto
    function getEnrolledCoursesAndSubjects() {
        const enrollments = DB.getAll();
        const currentYear = new Date().getFullYear();

        const courseData = {
            '1ro': { subjects: new Set(), specialties: new Set(), bySpecialty: { TAE: new Set(), TC: new Set(), TG: new Set() } },
            '2do': { subjects: new Set(), specialties: new Set(), bySpecialty: { TAE: new Set(), TC: new Set(), TG: new Set() } },
            '3ro': { subjects: new Set(), specialties: new Set(), bySpecialty: { TAE: new Set(), TC: new Set(), TG: new Set() } },
            '4to': { subjects: new Set(), specialties: new Set(), bySpecialty: { TAE: new Set(), TC: new Set(), TG: new Set() } },
            '5to': { subjects: new Set(), specialties: new Set(), bySpecialty: { TAE: new Set(), TC: new Set(), TG: new Set() } },
            '6to': { subjects: new Set(), specialties: new Set(), bySpecialty: { TAE: new Set(), TC: new Set(), TG: new Set() } },
            '7mo': { subjects: new Set(), specialties: new Set(), bySpecialty: { TAE: new Set(), TC: new Set(), TG: new Set() } }
        };

        enrollments.forEach(rec => {
            if (rec.createdAt) {
                const y = new Date(rec.createdAt).getFullYear();
                if (!isNaN(y) && y < currentYear) return;
            }

            if (!rec.subjects || !Array.isArray(rec.subjects)) return;

            rec.subjects.forEach(sub => {
                const rawYear = (sub.owedYear || sub.course || rec.currentCourse || '').trim();
                let cCode = null;
                if (rawYear.includes('1ro') || rawYear.includes('1er')) cCode = '1ro';
                else if (rawYear.includes('2do')) cCode = '2do';
                else if (rawYear.includes('3ro') || rawYear.includes('3er')) cCode = '3ro';
                else if (rawYear.includes('4to')) cCode = '4to';
                else if (rawYear.includes('5to')) cCode = '5to';
                else if (rawYear.includes('6to')) cCode = '6to';
                else if (rawYear.includes('7mo')) cCode = '7mo';

                let spec = null;
                if (rawYear.includes('TAE') || rec.currentSpecialty === 'TAE' || (rec.currentCourse && rec.currentCourse.includes('TAE'))) spec = 'TAE';
                else if (rawYear.includes('TC') || rec.currentSpecialty === 'TC' || (rec.currentCourse && rec.currentCourse.includes('TC'))) spec = 'TC';
                else if (rawYear.includes('TG') || rec.currentSpecialty === 'TG' || (rec.currentCourse && rec.currentCourse.includes('TG'))) spec = 'TG';

                const subName = (sub.subjectName || '').trim();

                if (!cCode && subName) {
                    for (const [key, list] of Object.entries(listado_materias)) {
                        if (list.some(s => normalizeSubjectName(s) === normalizeSubjectName(subName))) {
                            if (key.includes('1er')) cCode = '1ro';
                            else if (key.includes('2do')) cCode = '2do';
                            else if (key.includes('3er')) cCode = '3ro';
                            else if (key.includes('4to')) cCode = '4to';
                            else if (key.includes('5to')) cCode = '5to';
                            else if (key.includes('6to')) cCode = '6to';
                            else if (key.includes('7mo')) cCode = '7mo';

                            if (key.includes('TAE')) spec = 'TAE';
                            else if (key.includes('TC')) spec = 'TC';
                            else if (key.includes('TG')) spec = 'TG';
                            break;
                        }
                    }
                }

                // Verificar que la materia realmente pertenezca al diseño curricular de ese curso
                if (cCode && subName) {
                    const isCicloBasico = ['1ro', '2do', '3ro'].includes(cCode);
                    if (isCicloBasico) {
                        if (!courseHasSubjectInPlan(cCode, '', subName)) {
                            return;
                        }
                    } else if (spec) {
                        if (!courseHasSubjectInPlan(cCode, spec, subName)) {
                            return;
                        }
                    }
                }

                if (cCode && courseData[cCode]) {
                    if (subName) {
                        courseData[cCode].subjects.add(subName.toLowerCase());
                        courseData[cCode].subjects.add(normalizeSubjectName(subName));
                        if (spec && courseData[cCode].bySpecialty[spec]) {
                            courseData[cCode].bySpecialty[spec].add(subName.toLowerCase());
                            courseData[cCode].bySpecialty[spec].add(normalizeSubjectName(subName));
                        }
                    }
                    if (spec) courseData[cCode].specialties.add(spec);
                }
            });
        });

        return courseData;
    }

    function updateMesaCourseChipsUI() {
        const chips = document.querySelectorAll('#mesaCourseChipsGrid .course-chip');
        const countBadge = document.getElementById('mesaCourseCountBadge');
        const hiddenCourse = document.getElementById('mesaCourseSelect');
        const specialtyContainer = document.getElementById('mesaSpecialtyContainer');
        const specialtySelect = document.getElementById('mesaSpecialtySelect');

        const hasBasico = selectedMesaCourses.some(c => ['1ro', '2do', '3ro'].includes(c));
        const hasSuperior = selectedMesaCourses.some(c => ['4to', '5to', '6to', '7mo'].includes(c));
        const needsSpecialty = hasSuperior;

        const turnSelect = document.getElementById('mesaTurnSelect');
        const selectedTurn = turnSelect ? turnSelect.value : '';
        const enrolledData = getEnrolledCoursesAndSubjects(selectedTurn);

        chips.forEach(chip => {
            const c = chip.dataset.course;
            const hasStudents = (enrolledData[c] && enrolledData[c].subjects.size > 0);
            const isAllowedCourse = hasStudents || (editingMesaId && selectedMesaCourses.includes(c));

            // En creación de mesas solo aparece para elegir los cursos que tengan al menos un alumno inscripto
            if (!isAllowedCourse) {
                chip.style.display = 'none';
                if (selectedMesaCourses.includes(c)) {
                    selectedMesaCourses = selectedMesaCourses.filter(item => item !== c);
                }
            } else {
                chip.style.display = '';
            }

            const isSel = selectedMesaCourses.includes(c);
            chip.classList.toggle('is-selected', isSel);

            // Requerimiento 4: Bloqueo dinámico de combinación Ciclo Básico vs Ciclo Superior
            if (hasBasico && ['4to', '5to', '6to', '7mo'].includes(c)) {
                chip.disabled = true;
                chip.classList.add('disabled-cycle');
                chip.title = "No disponible: la mesa actual está configurada para Ciclo Básico";
            } else if (hasSuperior && ['1ro', '2do', '3ro'].includes(c)) {
                chip.disabled = true;
                chip.classList.add('disabled-cycle');
                chip.title = "No disponible: la mesa actual está configurada para Ciclo Superior";
            } else {
                chip.disabled = false;
                chip.classList.remove('disabled-cycle');
                chip.removeAttribute('title');
            }
        });

        if (countBadge) {
            countBadge.textContent = `${selectedMesaCourses.length} de 3 seleccionados`;
        }

        if (hiddenCourse) {
            hiddenCourse.value = selectedMesaCourses.join(', ');
        }

        if (specialtyContainer) {
            if (hasSuperior) {
                specialtyContainer.classList.add('visible');
                specialtyContainer.style.display = 'block';
                if (specialtySelect) {
                    specialtySelect.removeAttribute('disabled');
                    specialtySelect.setAttribute('required', 'true');

                    const availableSpecs = new Set();
                    selectedMesaCourses.forEach(c => {
                        if (enrolledData[c] && enrolledData[c].specialties) {
                            enrolledData[c].specialties.forEach(sp => availableSpecs.add(sp));
                        }
                    });

                    Array.from(specialtySelect.options).forEach(opt => {
                        if (!opt.value) return;
                        if (availableSpecs.size > 0 && !availableSpecs.has(opt.value) && !editingMesaId) {
                            opt.disabled = true;
                            opt.hidden = true;
                        } else {
                            opt.disabled = false;
                            opt.hidden = false;
                        }
                    });

                    if (!editingMesaId && availableSpecs.size > 0) {
                        if (!specialtySelect.value || !availableSpecs.has(specialtySelect.value)) {
                            specialtySelect.value = Array.from(availableSpecs)[0];
                        }
                    }
                }
            } else {
                specialtyContainer.classList.remove('visible');
                specialtyContainer.style.display = 'none';
                if (specialtySelect) {
                    specialtySelect.removeAttribute('required');
                    specialtySelect.setAttribute('disabled', 'true');
                    specialtySelect.value = '';
                }
            }
            if (specialtySelect && specialtySelect._refreshCustomOptions) {
                specialtySelect._refreshCustomOptions();
            }
        }
    }

    function initDirectivosEvents() {
        const formCreateMesa = document.getElementById('formCreateMesa');
        const chipsGrid = document.getElementById('mesaCourseChipsGrid');
        const mesaSpecialtySelect = document.getElementById('mesaSpecialtySelect');
        const btnCancelEditMesa = document.getElementById('btnCancelEditMesa');

        if (chipsGrid) {
            chipsGrid.addEventListener('click', (e) => {
                const btn = e.target.closest('.course-chip');
                if (!btn) return;
                const course = btn.dataset.course;
                if (!course) return;

                const isBasicoCourse = ['1ro', '2do', '3ro'].includes(course);
                const isSuperiorCourse = ['4to', '5to', '6to', '7mo'].includes(course);

                if (selectedMesaCourses.includes(course)) {
                    selectedMesaCourses = selectedMesaCourses.filter(c => c !== course);
                    selectedMesaSubjects = [];
                } else {
                    const hasBasico = selectedMesaCourses.some(c => ['1ro', '2do', '3ro'].includes(c));
                    const hasSuperior = selectedMesaCourses.some(c => ['4to', '5to', '6to', '7mo'].includes(c));

                    if (isBasicoCourse && hasSuperior) {
                        alert("No se pueden combinar cursos de Ciclo Básico con Ciclo Superior. Deselecciona los cursos de Ciclo Superior para elegir Ciclo Básico.");
                        return;
                    }
                    if (isSuperiorCourse && hasBasico) {
                        alert("No se pueden combinar cursos de Ciclo Superior con Ciclo Básico. Deselecciona los cursos de Ciclo Básico para elegir Ciclo Superior.");
                        return;
                    }

                    if (selectedMesaCourses.length >= 3) {
                        alert("Solo puedes seleccionar hasta 3 cursos a la vez para la mesa de examen.");
                        return;
                    }
                    selectedMesaCourses.push(course);
                    selectedMesaSubjects = [];
                }

                chipsGrid.classList.remove('field-error-warning');
                const parent = chipsGrid.parentElement;
                if (parent) {
                    const msg = parent.querySelector('.field-error-msg');
                    if (msg) msg.remove();
                }

                updateMesaCourseChipsUI();
                updateMesaSubjectOptions();
            });
        }

        if (mesaSpecialtySelect) {
            mesaSpecialtySelect.addEventListener('change', () => {
                updateMesaSubjectOptions();
            });
        }

        const mesaTurnSelect = document.getElementById('mesaTurnSelect');
        if (mesaTurnSelect) {
            mesaTurnSelect.value = typeof getCurrentExamTurn === 'function' ? getCurrentExamTurn() : 'Febrero / Marzo';
            if (mesaTurnSelect._refreshCustomOptions) mesaTurnSelect._refreshCustomOptions();
            mesaTurnSelect.addEventListener('change', () => {
                updateMesaDateConstraints();
                updateMesaCourseChipsUI();
                updateMesaSubjectOptions();
            });
            updateMesaDateConstraints();
        }

        if (formCreateMesa) {
            formCreateMesa.addEventListener('submit', (e) => {
                e.preventDefault();
                handleCreateMesa();
            });
        }

        const btnSubmitMesa = document.getElementById('btnSubmitMesa');
        if (btnSubmitMesa) {
            btnSubmitMesa.addEventListener('click', (e) => {
                e.preventDefault();
                handleCreateMesa();
            });
        }

        if (btnCancelEditMesa) {
            btnCancelEditMesa.addEventListener('click', () => {
                cancelEditMesa();
            });
        }

        // Impedir que se escriban números en los campos de Tribunal Docente
        ['mesaPresDocente', 'mesaVocal1Docente', 'mesaVocal2Docente'].forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                input.addEventListener('input', () => {
                    if (/\d/.test(input.value)) {
                        input.value = input.value.replace(/\d/g, '');
                    }
                });
            }
        });

        const filterCourseAbono = document.getElementById('filterCourseAbono');
        const filterTurnAbono = document.getElementById('filterTurnAbono');
        const filterStatusAbono = document.getElementById('filterStatusAbono');
        const searchAbonoInput = document.getElementById('searchAbonoInput');
        const btnClearAbonosTable = document.getElementById('btnClearAbonosTable');

        if (filterTurnAbono) {
            filterTurnAbono.addEventListener('change', () => {
                renderAbonosTab();
            });
        }

        [filterCourseAbono, filterStatusAbono].forEach(select => {
            if (select) select.addEventListener('change', renderAbonosTab);
        });

        if (searchAbonoInput) {
            searchAbonoInput.addEventListener('input', renderAbonosTab);
        }

        if (btnClearAbonosTable) {
            btnClearAbonosTable.addEventListener('click', () => confirmClearTable('abonos'));
        }

        const filterCourseNota = document.getElementById('filterCourseNota');
        const filterSubjectNota = document.getElementById('filterSubjectNota');
        const filterTurnNota = document.getElementById('filterTurnNota');
        const filterApprovalNota = document.getElementById('filterApprovalNota');
        const btnClearNotasTable = document.getElementById('btnClearNotasTable');

        if (filterTurnNota) {
            filterTurnNota.addEventListener('change', () => {
                renderNotasTab();
            });
        }

        [filterCourseNota, filterSubjectNota, filterApprovalNota].forEach(select => {
            if (select) select.addEventListener('change', renderNotasTab);
        });

        if (btnClearNotasTable) {
            btnClearNotasTable.addEventListener('click', () => confirmClearTable('notas'));
        }

        const statYearSelect = document.getElementById('statYearSelect');
        if (statYearSelect) {
            statYearSelect.value = String(new Date().getFullYear());
            statYearSelect.addEventListener('change', () => {
                renderStatsTab();
            });
        }

        const selectMesaListado = document.getElementById('selectMesaListado');
        const btnPrintRoster = document.getElementById('btnPrintRoster');
        const btnDownloadRosterPdf = document.getElementById('btnDownloadRosterPdf');

        if (selectMesaListado) {
            selectMesaListado.addEventListener('change', renderListadosTab);
        }

        if (btnPrintRoster) {
            btnPrintRoster.addEventListener('click', () => {
                renderListadosTab();
                const escudoSrc = window.ESCUDO_BASE64 || document.getElementById('rosterEscudoImg')?.src || 'imagenes/escudo.png';
                const logoSrc = window.LOGO_BASE64 || document.getElementById('rosterLogoImg')?.src || 'imagenes/logo.png';

                const page1El = document.querySelector('.roster-page-1');
                const page2ContentEl = document.querySelector('.roster-page-2-content');

                if (!page1El || !page2ContentEl) {
                    window.print();
                    return;
                }

                // Clones para asegurar que las imágenes y contenido estén limpios
                const p1Clone = page1El.cloneNode(true);
                const p2Clone = page2ContentEl.cloneNode(true);

                const escImg = p1Clone.querySelector('#rosterEscudoImg');
                const logImg = p1Clone.querySelector('#rosterLogoImg');
                if (escImg) escImg.src = escudoSrc;
                if (logImg) logImg.src = logoSrc;

                // Remover iframe previo si existiera
                const existingFrame = document.getElementById('rosterPrintFrame');
                if (existingFrame) existingFrame.remove();

                const iframe = document.createElement('iframe');
                iframe.id = 'rosterPrintFrame';
                iframe.style.position = 'fixed';
                iframe.style.right = '0';
                iframe.style.bottom = '0';
                iframe.style.width = '0';
                iframe.style.height = '0';
                iframe.style.border = '0';
                iframe.style.visibility = 'hidden';
                document.body.appendChild(iframe);

                const frameDoc = iframe.contentWindow.document;
                frameDoc.open();
                frameDoc.write(`
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset="utf-8">
                        <title>Acta Volante de Examen</title>
                        <style>
                            @page {
                                size: A4 portrait;
                                margin: 0;
                            }
                            * {
                                box-sizing: border-box !important;
                                -webkit-print-color-adjust: exact !important;
                                print-color-adjust: exact !important;
                            }
                            html, body {
                                margin: 0 !important;
                                padding: 0 !important;
                                background: #ffffff !important;
                                color: #000000 !important;
                                font-family: Arial, Helvetica, sans-serif !important;
                            }
                            .print-sheet-1 {
                                width: 210mm !important;
                                height: 297mm !important;
                                max-width: 210mm !important;
                                max-height: 297mm !important;
                                box-sizing: border-box !important;
                                padding: 9mm 11mm !important;
                                display: flex !important;
                                flex-direction: column !important;
                                justify-content: flex-start !important;
                                page-break-after: always !important;
                                break-after: page !important;
                                overflow: hidden !important;
                                background: #ffffff !important;
                            }
                            .print-sheet-2 {
                                width: 210mm !important;
                                height: 297mm !important;
                                max-width: 210mm !important;
                                max-height: 297mm !important;
                                box-sizing: border-box !important;
                                position: relative !important;
                                page-break-before: always !important;
                                break-before: page !important;
                                overflow: hidden !important;
                                background: #ffffff !important;
                            }
                            .print-sheet-2-inner {
                                width: 277mm !important;
                                height: 186mm !important;
                                position: absolute !important;
                                top: 10mm !important;
                                left: 12mm !important;
                                transform-origin: 0 0 !important;
                                transform: rotate(90deg) translateY(-100%) !important;
                                display: flex !important;
                                flex-direction: column !important;
                                justify-content: flex-start !important;
                                background: #ffffff !important;
                            }
                            table {
                                width: 100% !important;
                                border-collapse: collapse !important;
                                background: #ffffff !important;
                            }
                            th, td {
                                border: 1px solid #000000 !important;
                                color: #000000 !important;
                                box-sizing: border-box !important;
                            }
                            .roster-desaprobacion-table {
                                flex: 1 !important;
                                height: 100% !important;
                            }
                        </style>
                    </head>
                    <body>
                        <div class="print-sheet-1">${p1Clone.innerHTML}</div>
                        <div class="print-sheet-2"><div class="print-sheet-2-inner">${p2Clone.innerHTML}</div></div>
                    </body>
                    </html>
                `);
                frameDoc.close();

                setTimeout(() => {
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                    setTimeout(() => {
                        iframe.remove();
                    }, 5000);
                }, 250);
            });
        }

        if (btnDownloadRosterPdf) {
            btnDownloadRosterPdf.addEventListener('click', handleDownloadRosterPdf);
        }

        const searchStatSubjectInput = document.getElementById('searchStatSubjectInput');
        if (searchStatSubjectInput) {
            searchStatSubjectInput.addEventListener('input', () => {
                renderStatSubjectSearch();
            });
        }

        initHabilitarEvents();
    }

    function refreshDirectivosDashboard() {
        populateAbonoFilters();
        populateNotaFilters();
        renderStatsTab();
        renderMesasTab();
        updateMesaCourseChipsUI();
        updateMesaSubjectOptions();
        renderAbonosTab();
        renderListadosTab();
        renderNotasTab();
        renderHabilitarTab();
    }

    // ==========================================
    // PESTAÑA: HABILITAR PREINSCRIPCIONES
    // ==========================================

    function renderHabilitarTab() {
        const cfg = DB.getConfig();
        const toggle = document.getElementById('configEnableToggle');
        const startInput = document.getElementById('configFechaInicio');
        const endInput = document.getElementById('configFechaFin');

        const todayStr = new Date().toISOString().split('T')[0];
        const maxDateStr = '2031-12-31';

        const isEnabled = String(cfg.preinscripcion_habilitada) === '1' || cfg.preinscripcion_habilitada === true;

        if (startInput) {
            startInput.removeAttribute('min');
            startInput.max = maxDateStr;
        }
        if (endInput) {
            endInput.min = todayStr;
            endInput.max = maxDateStr;
        }

        if (toggle) toggle.checked = isEnabled;
        if (startInput) startInput.value = cfg.preinscripcion_fecha_inicio || '';
        if (endInput) endInput.value = cfg.preinscripcion_fecha_fin || '';

        const importeInput = document.getElementById('configImporteInscripcion');
        if (importeInput) importeInput.value = cfg.preinscripcion_importe || '3000';

        savedHabilitarState = getHabilitarFormState();
        updateConfigStatusPreview();
    }

    function updateConfigStatusPreview() {
        const toggle = document.getElementById('configEnableToggle');
        const startInput = document.getElementById('configFechaInicio');
        const endInput = document.getElementById('configFechaFin');
        const card = document.getElementById('configStatusCard');
        const icon = document.getElementById('configStatusIcon');
        const title = document.getElementById('configStatusTitle');
        const desc = document.getElementById('configStatusDesc');
        const badge = document.getElementById('configStatusBadge');

        if (!card || !icon || !title || !desc || !badge) return;

        const isEnabled = toggle ? toggle.checked : true;
        const start = startInput ? startInput.value : '';
        const end = endInput ? endInput.value : '';

        const d = new Date();
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const today = `${year}-${month}-${day}`;

        if (startInput) {
            startInput.removeAttribute('min');
        }

        const formatD = (str) => {
            if (!str) return '';
            const parts = str.split('-');
            if (parts.length === 3) return `${parts[2]}/${parts[1]}/${parts[0]}`;
            return str;
        };

        if (!isEnabled) {
            card.style.background = 'rgba(229, 57, 53, 0.08)';
            card.style.borderColor = 'rgba(229, 57, 53, 0.35)';
            icon.style.color = '#ef5350';
            icon.textContent = 'cancel';
            title.textContent = 'Preinscripciones Deshabilitadas Manualmente';
            desc.textContent = 'El formulario de alumnos se encuentra bloqueado por directivos.';
            badge.style.background = 'rgba(229, 57, 53, 0.2)';
            badge.style.color = '#ef5350';
            badge.style.borderColor = 'rgba(229, 57, 53, 0.4)';
            badge.textContent = 'Deshabilitado';
        } else if (start && today < start) {
            card.style.background = 'rgba(255, 183, 77, 0.08)';
            card.style.borderColor = 'rgba(255, 183, 77, 0.35)';
            icon.style.color = '#ffb74d';
            icon.textContent = 'schedule';
            title.textContent = `Apertura Programada: ${formatD(start)}`;
            desc.textContent = `Aún no ha comenzado el período de preinscripción. El formulario permanecerá bloqueado hasta la fecha de inicio.`;
            badge.style.background = 'rgba(255, 183, 77, 0.2)';
            badge.style.color = '#ffb74d';
            badge.style.borderColor = 'rgba(255, 183, 77, 0.4)';
            badge.textContent = 'Próximamente';
        } else if (end && today > end) {
            card.style.background = 'rgba(229, 57, 53, 0.08)';
            card.style.borderColor = 'rgba(229, 57, 53, 0.35)';
            icon.style.color = '#ef5350';
            icon.textContent = 'event_busy';
            title.textContent = `Período Vencido (Cerró el ${formatD(end)})`;
            desc.textContent = 'La fecha límite de preinscripción ya ha pasado. El formulario está bloqueado para los alumnos.';
            badge.style.background = 'rgba(229, 57, 53, 0.2)';
            badge.style.color = '#ef5350';
            badge.style.borderColor = 'rgba(229, 57, 53, 0.4)';
            badge.textContent = 'Vencido';
        } else {
            card.style.background = 'rgba(76, 175, 80, 0.08)';
            card.style.borderColor = 'rgba(76, 175, 80, 0.35)';
            icon.style.color = '#4caf50';
            icon.textContent = 'check_circle';
            title.textContent = 'Preinscripciones Habilitadas y Vigentes';
            desc.textContent = `Los alumnos pueden acceder normalmente al formulario (vigente ${start ? 'desde el ' + formatD(start) : ''} hasta el ${formatD(end) || 'fin de ciclo'}).`;
            badge.style.background = 'rgba(76, 175, 80, 0.2)';
            badge.style.color = '#4caf50';
            badge.style.borderColor = 'rgba(76, 175, 80, 0.4)';
            badge.textContent = 'Habilitado';
        }
    }

    function initHabilitarEvents() {
        const toggle = document.getElementById('configEnableToggle');
        const startInput = document.getElementById('configFechaInicio');
        const endInput = document.getElementById('configFechaFin');
        const importeInput = document.getElementById('configImporteInscripcion');
        const btnSave = document.getElementById('btnSaveConfigHabilitar');

        if (toggle) toggle.addEventListener('change', updateConfigStatusPreview);
        if (startInput) startInput.addEventListener('change', updateConfigStatusPreview);
        if (endInput) endInput.addEventListener('change', updateConfigStatusPreview);

        if (importeInput) {
            importeInput.addEventListener('keydown', (e) => {
                if (e.key === 'e' || e.key === 'E' || e.key === '+' || e.key === '-') {
                    e.preventDefault();
                }
            });
        }

        if (btnSave) {
            btnSave.addEventListener('click', async () => {
                const isEnabled = toggle ? (toggle.checked ? '1' : '0') : '1';
                const start = startInput ? startInput.value : '';
                const end = endInput ? endInput.value : '';

                const todayStr = new Date().toISOString().split('T')[0];
                const maxDateStr = '2031-12-31';

                if (start && start > maxDateStr) {
                    alert("La fecha de inicio no puede ser posterior al 31/12/2031.");
                    return;
                }
                if (end && end < todayStr) {
                    alert("La fecha de cierre no puede ser anterior a la fecha actual (" + todayStr + ").");
                    return;
                }
                if (end && end > maxDateStr) {
                    alert("La fecha de cierre no puede ser posterior al 31/12/2031.");
                    return;
                }
                if (start && end && start > end) {
                    alert("La fecha de inicio no puede ser posterior a la fecha de fin.");
                    return;
                }

                const importeVal = importeInput ? parseFloat(importeInput.value) : 3000;
                if (isNaN(importeVal) || importeVal < 2500 || importeVal > 30000) {
                    alert("El importe de la inscripción debe ser un valor entre $2500 y $30000.");
                    if (importeInput) importeInput.focus();
                    return;
                }

                const executeSaveConfig = async () => {
                    btnSave.disabled = true;
                    const prevHtml = btnSave.innerHTML;
                    btnSave.innerHTML = '<span class="material-symbols-outlined spin" style="animation: spin 1s infinite linear;">sync</span> Guardando...';

                    await DB.saveConfig({
                        preinscripcion_habilitada: isEnabled,
                        preinscripcion_fecha_inicio: start,
                        preinscripcion_fecha_fin: end,
                        preinscripcion_importe: String(importeVal)
                    });

                    savedHabilitarState = getHabilitarFormState();
                    updateConfigStatusPreview();
                    if (typeof updateStudentPreinscripcionStatus === "function") updateStudentPreinscripcionStatus();

                    setTimeout(() => {
                        btnSave.disabled = false;
                        btnSave.innerHTML = prevHtml;
                        alert("¡Configuración de preinscripciones guardada con éxito!");
                    }, 350);
                };

                // Si desactiva la preinscripción, confirmar antes de guardar
                if (isEnabled === '0') {
                    showInstitutionalConfirm(
                        "¿Desea salir sin habilitar la preinscripción de alumnos?",
                        "Has desactivado la habilitación de preinscripciones. Si guardas esta configuración, los alumnos no tendrán acceso al formulario ni podrán preinscribirse.",
                        "Guardar sin habilitar",
                        "Permanecer aquí",
                        (confirmed) => {
                            if (confirmed) {
                                executeSaveConfig();
                            }
                        }
                    );
                    return;
                }

                await executeSaveConfig();
            });
        }
    }

    function getCourseLabel(course) {
        if (!course) return '';
        if (course === '1er AÑO' || course === '1ro' || course === '1er') return '1er AÑO';
        if (course === '2do AÑO' || course === '2do') return '2do AÑO';
        if (course === '3er AÑO' || course === '3ro' || course === '3er') return '3er AÑO';
        if (course === '4to') return '4to AÑO';
        if (course === '5to') return '5to AÑO';
        if (course === '6to') return '6to AÑO';
        if (course === '7mo') return '7mo AÑO';
        return course;
    }

    function populateAbonoFilters() {
        const enrollments = DB.getAll();
        const coursesSet = new Set();
        const turnsSet = new Set();
        let hasPaid = false;
        let hasUnpaid = false;

        enrollments.forEach(rec => {
            if (rec.subjects && Array.isArray(rec.subjects)) {
                rec.subjects.forEach(sub => {
                    if (sub.owedYear) coursesSet.add(sub.owedYear);
                    if (sub.examTurn) turnsSet.add(sub.examTurn);
                    if (sub.paid) hasPaid = true;
                    else hasUnpaid = true;
                });
            }
        });

        const standardCourseOrder = [
            '1er AÑO', '2do AÑO', '3er AÑO',
            '4to TAE', '4to TC', '4to TG',
            '5to TAE', '5to TC', '5to TG',
            '6to TAE', '6to TC', '6to TG',
            '7mo TAE', '7mo TC', '7mo TG'
        ];

        const sortedCourses = Array.from(coursesSet).sort((a, b) => {
            const idxA = standardCourseOrder.indexOf(a);
            const idxB = standardCourseOrder.indexOf(b);
            if (idxA !== -1 && idxB !== -1) return idxA - idxB;
            if (idxA !== -1) return -1;
            if (idxB !== -1) return 1;
            return a.localeCompare(b);
        });

        const standardTurnOrder = ['Febrero / Marzo', 'Julio / Agosto', 'Noviembre / Diciembre'];
        const sortedTurns = Array.from(turnsSet).sort((a, b) => {
            const idxA = standardTurnOrder.indexOf(a);
            const idxB = standardTurnOrder.indexOf(b);
            if (idxA !== -1 && idxB !== -1) return idxA - idxB;
            return a.localeCompare(b);
        });

        const filterCourse = document.getElementById('filterCourseAbono');
        if (filterCourse) {
            const curVal = filterCourse.value;
            filterCourse.innerHTML = '<option value="">Año</option>';
            sortedCourses.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c;
                opt.textContent = getCourseLabel(c);
                filterCourse.appendChild(opt);
            });
            if (curVal && coursesSet.has(curVal)) {
                filterCourse.value = curVal;
            } else {
                filterCourse.value = '';
            }
            if (filterCourse._refreshCustomOptions) filterCourse._refreshCustomOptions();
        }

        const filterTurn = document.getElementById('filterTurnAbono');
        const currentActiveTurn = typeof getCurrentExamTurn === 'function' ? getCurrentExamTurn() : 'Febrero / Marzo';
        if (filterTurn) {
            const curVal = filterTurn.value;
            filterTurn.innerHTML = '';
            standardTurnOrder.forEach(t => {
                const opt = document.createElement('option');
                opt.value = t;
                opt.textContent = t;
                filterTurn.appendChild(opt);
            });
            if (curVal && standardTurnOrder.includes(curVal)) {
                filterTurn.value = curVal;
            } else {
                filterTurn.value = currentActiveTurn;
            }
            if (filterTurn._refreshCustomOptions) filterTurn._refreshCustomOptions();
        }

        const filterStatus = document.getElementById('filterStatusAbono');
        if (filterStatus) {
            const curVal = filterStatus.value;
            filterStatus.innerHTML = '<option value="">Estado</option>';
            if (hasPaid) {
                const opt = document.createElement('option');
                opt.value = 'paid';
                opt.textContent = 'ABONADO';
                filterStatus.appendChild(opt);
            }
            if (hasUnpaid) {
                const opt = document.createElement('option');
                opt.value = 'unpaid';
                opt.textContent = 'PENDIENTE';
                filterStatus.appendChild(opt);
            }
            if (curVal && ((curVal === 'paid' && hasPaid) || (curVal === 'unpaid' && hasUnpaid))) {
                filterStatus.value = curVal;
            } else {
                filterStatus.value = '';
            }
            if (filterStatus._refreshCustomOptions) filterStatus._refreshCustomOptions();
        }
    }

    function populateNotaFilters() {
        const enrollments = DB.getAll();
        const coursesSet = new Set();
        const subjectsSet = new Set();
        const turnsSet = new Set();
        let hasPending = false;
        let hasPassed = false;
        let hasFailed = false;

        enrollments.forEach(rec => {
            if (rec.subjects && Array.isArray(rec.subjects)) {
                rec.subjects.forEach(sub => {
                    if (sub.owedYear) coursesSet.add(sub.owedYear);
                    if (sub.subjectName) subjectsSet.add(sub.subjectName);
                    if (sub.examTurn) turnsSet.add(sub.examTurn);
                    const hasGrade = sub.grade !== null && sub.grade !== undefined && sub.grade !== '' && !isNaN(Number(sub.grade));
                    if (!hasGrade) {
                        hasPending = true;
                    } else {
                        const g = Number(sub.grade);
                        if (g >= 6) hasPassed = true;
                        else hasFailed = true;
                    }
                });
            }
        });

        const standardCourseOrder = [
            '1er AÑO', '2do AÑO', '3er AÑO',
            '4to TAE', '4to TC', '4to TG',
            '5to TAE', '5to TC', '5to TG',
            '6to TAE', '6to TC', '6to TG',
            '7mo TAE', '7mo TC', '7mo TG'
        ];

        const sortedCourses = Array.from(coursesSet).sort((a, b) => {
            const idxA = standardCourseOrder.indexOf(a);
            const idxB = standardCourseOrder.indexOf(b);
            if (idxA !== -1 && idxB !== -1) return idxA - idxB;
            if (idxA !== -1) return -1;
            if (idxB !== -1) return 1;
            return a.localeCompare(b);
        });

        const standardTurnOrder = ['Febrero / Marzo', 'Julio / Agosto', 'Noviembre / Diciembre'];
        const sortedTurns = Array.from(turnsSet).sort((a, b) => {
            const idxA = standardTurnOrder.indexOf(a);
            const idxB = standardTurnOrder.indexOf(b);
            if (idxA !== -1 && idxB !== -1) return idxA - idxB;
            return a.localeCompare(b);
        });

        const filterCourse = document.getElementById('filterCourseNota');
        if (filterCourse) {
            const curVal = filterCourse.value;
            filterCourse.innerHTML = '<option value="">Año</option>';
            sortedCourses.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c;
                opt.textContent = getCourseLabel(c);
                filterCourse.appendChild(opt);
            });
            if (curVal && coursesSet.has(curVal)) {
                filterCourse.value = curVal;
            } else {
                filterCourse.value = '';
            }
            if (filterCourse._refreshCustomOptions) filterCourse._refreshCustomOptions();
        }

        const filterSubject = document.getElementById('filterSubjectNota');
        if (filterSubject) {
            const curVal = filterSubject.value;
            filterSubject.innerHTML = '<option value="">Materia</option>';
            const sortedSubjects = Array.from(subjectsSet).sort((a, b) => a.localeCompare(b));
            sortedSubjects.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s;
                opt.textContent = s;
                filterSubject.appendChild(opt);
            });
            if (curVal && subjectsSet.has(curVal)) {
                filterSubject.value = curVal;
            } else {
                filterSubject.value = '';
            }
            if (filterSubject._refreshCustomOptions) filterSubject._refreshCustomOptions();
        }

        const filterTurn = document.getElementById('filterTurnNota');
        const currentActiveTurn = typeof getCurrentExamTurn === 'function' ? getCurrentExamTurn() : 'Febrero / Marzo';
        if (filterTurn) {
            const curVal = filterTurn.value;
            filterTurn.innerHTML = '';
            standardTurnOrder.forEach(t => {
                const opt = document.createElement('option');
                opt.value = t;
                opt.textContent = t;
                filterTurn.appendChild(opt);
            });
            if (curVal && standardTurnOrder.includes(curVal)) {
                filterTurn.value = curVal;
            } else {
                filterTurn.value = currentActiveTurn;
            }
            if (filterTurn._refreshCustomOptions) filterTurn._refreshCustomOptions();
        }

        const filterApproval = document.getElementById('filterApprovalNota');
        if (filterApproval) {
            const curVal = filterApproval.value;
            filterApproval.innerHTML = '<option value="">Estado</option>';
            if (hasPending) {
                const opt = document.createElement('option');
                opt.value = 'pending';
                opt.textContent = 'Sin Calificar';
                filterApproval.appendChild(opt);
            }
            if (hasPassed) {
                const opt = document.createElement('option');
                opt.value = 'passed';
                opt.textContent = 'Aprobados';
                filterApproval.appendChild(opt);
            }
            if (hasFailed) {
                const opt = document.createElement('option');
                opt.value = 'failed';
                opt.textContent = 'Desaprobados';
                filterApproval.appendChild(opt);
            }
            const valid = [];
            if (hasPending) valid.push('pending');
            if (hasPassed) valid.push('passed');
            if (hasFailed) valid.push('failed');
            if (curVal && valid.includes(curVal)) {
                filterApproval.value = curVal;
            } else {
                filterApproval.value = '';
            }
            if (filterApproval._refreshCustomOptions) filterApproval._refreshCustomOptions();
        }
    }

    function confirmClearTable(tabName) {
        const filterTurn = (tabName === 'abonos') 
            ? document.getElementById('filterTurnAbono')?.value 
            : document.getElementById('filterTurnNota')?.value;
        const turnName = filterTurn || (typeof getCurrentExamTurn === 'function' ? getCurrentExamTurn() : 'Febrero / Marzo');

        showInstitutionalConfirm(
            `¿Desea vaciar el listado de alumnos de ${turnName}?`,
            `Al terminar este turno de examen, esta acción vaciará el listado de alumnos preinscriptos para el llamado "${turnName}", dejándolo limpio para el nuevo período. ¿Confirmas el vaciado de la tabla?`,
            "Vaciar tabla",
            "Cancelar",
            async (confirmed) => {
                if (confirmed) {
                    await DB.clearTurnEnrollments(turnName);
                    populateAbonoFilters();
                    populateNotaFilters();
                    renderAbonosTab();
                    renderNotasTab();
                    renderStatsTab();
                    alert(`La tabla de alumnos para "${turnName}" ha sido vaciada con éxito.`);
                }
            }
        );
    }

    function renderStatsTab() {
        const yearSelect = document.getElementById('statYearSelect');
        const selectedYear = yearSelect ? parseInt(yearSelect.value, 10) : new Date().getFullYear();
        const stats = DB.getAdvancedStats(selectedYear);

        const elStatTotalStudents = document.getElementById('statTotalStudents');
        const elStatTotalSubjects = document.getElementById('statTotalSubjects');
        const elStatPaidPercentage = document.getElementById('statPaidPercentage');
        const elStatAvgGrade = document.getElementById('statAvgGrade');
        const elStatBestTurnName = document.getElementById('statBestTurnName');
        const elStatBestTurnRate = document.getElementById('statBestTurnRate');

        if (elStatTotalStudents) elStatTotalStudents.textContent = stats.totalStudents;
        if (elStatTotalSubjects) elStatTotalSubjects.textContent = stats.totalSubjectEnrollments;
        if (elStatPaidPercentage) elStatPaidPercentage.textContent = stats.paidPercentage + '%';
        if (elStatAvgGrade) elStatAvgGrade.textContent = stats.overallAvgGrade;

        if (elStatBestTurnName) elStatBestTurnName.textContent = stats.bestTurn;
        if (elStatBestTurnRate) elStatBestTurnRate.textContent = `${stats.bestPassRate}% de Aprobados`;

        const turnBreakdownContainer = document.getElementById('turnBreakdownContainer');
        if (turnBreakdownContainer) {
            turnBreakdownContainer.innerHTML = '';
            Object.keys(stats.turnStats).forEach(t => {
                const st = stats.turnStats[t];
                const div = document.createElement('div');
                div.className = 'turn-stat-card';
                div.innerHTML = `
                    <div class="turn-stat-title">
                        <span>${esc(t)}</span>
                        <span class="turn-badge ${t === stats.bestTurn && st.gradedCount > 0 ? 'best' : ''}">${st.passRate}% Aprobación</span>
                    </div>
                    <div class="turn-progress-bar">
                        <div class="turn-progress-fill" style="width: ${st.passRate}%"></div>
                    </div>
                    <div class="turn-stat-details">
                        <span>Inscriptos: ${st.total}</span>
                        <span>Aprobados: ${st.passed}</span>
                        <span>Desaprobados: ${st.failed}</span>
                        <span>Promedio: ${st.avgGrade}</span>
                    </div>
                `;
                turnBreakdownContainer.appendChild(div);
            });
        }

        const topSubjectsList = document.getElementById('topSubjectsList');
        if (topSubjectsList) {
            topSubjectsList.innerHTML = '';
            if (!stats.topSubjects || stats.topSubjects.length === 0) {
                topSubjectsList.innerHTML = '<div style="color: var(--color-text-muted); font-size: 0.85rem; padding: 0.5rem 0;">No hay inscripciones registradas para este ciclo lectivo.</div>';
            } else {
                stats.topSubjects.slice(0, 3).forEach((sub, idx) => {
                    const item = document.createElement('div');
                    item.className = 'top-subject-item';
                    item.innerHTML = `
                        <span class="top-rank">#${idx + 1}</span>
                        <span class="top-name">${esc(sub.name)}</span>
                        <span class="top-count">${sub.count} inscripto(s)</span>
                    `;
                    topSubjectsList.appendChild(item);
                });
            }
        }

        renderStatSubjectSearch();
    }

    function renderStatSubjectSearch() {
        const resultsContainer = document.getElementById('searchStatSubjectResults');
        const searchInput = document.getElementById('searchStatSubjectInput');
        if (!resultsContainer) return;

        const query = searchInput ? normalizeSubjectName(searchInput.value) : '';
        if (!query) {
            resultsContainer.innerHTML = '';
            resultsContainer.style.display = 'none';
            return;
        }

        resultsContainer.style.display = 'flex';
        const enrollments = DB.getAll();
        const yearSelect = document.getElementById('statYearSelect');
        const currentYear = yearSelect ? parseInt(yearSelect.value, 10) : new Date().getFullYear();

        // Construir catálogo unificado de materias desde listado_materias + inscripciones
        const subjectsByNorm = {};

        Object.keys(listado_materias).forEach(courseKey => {
            (listado_materias[courseKey] || []).forEach(subName => {
                const norm = normalizeSubjectName(subName);
                if (!subjectsByNorm[norm]) {
                    subjectsByNorm[norm] = {
                        name: subName,
                        norm: norm,
                        count: 0,
                        family: getSubjectFamily(subName),
                        roman: getSubjectRomanNumeral(subName)
                    };
                }
            });
        });

        enrollments.forEach(rec => {
            if (rec.createdAt) {
                const recYear = parseInt(String(rec.createdAt).substring(0, 4), 10) || new Date(rec.createdAt).getFullYear();
                if (recYear !== currentYear) return;
            }
            if (!rec.subjects || !Array.isArray(rec.subjects)) return;
            rec.subjects.forEach(sub => {
                if (!sub.subjectName) return;
                let disp = String(sub.subjectName).trim();
                const normCheck = normalizeSubjectName(disp).replace(/[()]/g, ' ').replace(/\s+/g, ' ').trim();
                if (normCheck === 'lengua extranjera ingles') {
                    disp = 'Lengua Extranjera (Inglés)';
                }
                const norm = normalizeSubjectName(disp);
                if (!subjectsByNorm[norm]) {
                    subjectsByNorm[norm] = {
                        name: disp,
                        norm: norm,
                        count: 0,
                        family: getSubjectFamily(disp),
                        roman: getSubjectRomanNumeral(disp)
                    };
                }
                subjectsByNorm[norm].count++;
            });
        });

        const allEntries = Object.values(subjectsByNorm);
        const filteredEntries = allEntries.filter(entry => {
            if (!query) return true;
            const matchName = entry.norm.includes(query);
            const matchFam = entry.family && normalizeSubjectName(entry.family).includes(query);
            return matchName || matchFam;
        });

        resultsContainer.innerHTML = '';

        if (filteredEntries.length === 0) {
            resultsContainer.innerHTML = `
                <div style="padding: 0.75rem; text-align: center; color: rgba(255,255,255,0.6); font-size: 0.85rem;">
                    No se encontraron materias que coincidan con la búsqueda.
                </div>
            `;
            return;
        }

        // Agrupar por familia solo cuando hay más de una materia numerada distinta (ej: I y II).
        // Si no hay más de una materia numerada (si todas son solo I, solo II o solo III, o sin numerar), se agregan todas juntas.
        const familyEntriesMap = {};
        filteredEntries.forEach(entry => {
            if (entry.family) {
                if (!familyEntriesMap[entry.family]) familyEntriesMap[entry.family] = [];
                familyEntriesMap[entry.family].push(entry);
            }
        });

        const multiNumberedFamilies = new Set();
        Object.keys(familyEntriesMap).forEach(fam => {
            const distinctRomans = new Set();
            familyEntriesMap[fam].forEach(e => {
                if (e.roman) distinctRomans.add(e.roman);
            });
            if (distinctRomans.size >= 2) {
                multiNumberedFamilies.add(fam);
            }
        });

        const generalList = [];
        filteredEntries.forEach(entry => {
            if (entry.family && multiNumberedFamilies.has(entry.family)) {
                return; // Va separada como familia
            }
            generalList.push(entry);
        });

        // Ordenar lista general por cantidad de inscriptos (desc) y alfabéticamente, manteniendo variantes de una misma familia (ej: Matemática y Matemática I) una abajo de la otra
        generalList.sort((a, b) => (b.count - a.count) || a.name.localeCompare(b.name));
        const orderedGeneralList = [];
        const visitedGeneralNorms = new Set();
        generalList.forEach(item => {
            if (visitedGeneralNorms.has(item.norm)) return;
            if (item.family) {
                const sameFam = generalList
                    .filter(x => x.family === item.family && !visitedGeneralNorms.has(x.norm))
                    .sort((a, b) => getRomanNumeralOrder(a.name) - getRomanNumeralOrder(b.name) || a.name.localeCompare(b.name));
                sameFam.forEach(sf => {
                    visitedGeneralNorms.add(sf.norm);
                    orderedGeneralList.push(sf);
                });
            } else {
                visitedGeneralNorms.add(item.norm);
                orderedGeneralList.push(item);
            }
        });

        orderedGeneralList.forEach(sub => {
            const item = document.createElement('div');
            item.className = 'top-subject-item';
            item.innerHTML = `
                <span class="top-name" style="flex: 1;">${esc(sub.name)}</span>
                <span class="top-count">${sub.count} alumno(s)</span>
            `;
            resultsContainer.appendChild(item);
        });

        // Renderizar familias que tienen más de una materia numerada (ej: I y II) separadas de las demás pero agregadas como familia (ordenadas: base, I, II, III una abajo de la otra)
        Array.from(multiNumberedFamilies).sort((a, b) => a.localeCompare(b)).forEach(fam => {
            const famItems = familyEntriesMap[fam].slice().sort((a, b) => {
                const ordDiff = getRomanNumeralOrder(a.name) - getRomanNumeralOrder(b.name);
                return ordDiff !== 0 ? ordDiff : a.name.localeCompare(b.name);
            });
            const totalFamCount = famItems.reduce((acc, cur) => acc + cur.count, 0);

            const famBlock = document.createElement('div');
            famBlock.className = 'mesa-subject-group';
            famBlock.style.marginTop = '0.6rem';
            famBlock.innerHTML = `
                <div class="mesa-subject-group-title" style="display: flex; justify-content: space-between; align-items: center;">
                    <span><span class="material-symbols-outlined" style="font-size: 0.95rem; vertical-align: middle;">hub</span> Familia: ${esc(fam)}</span>
                    <span style="font-size: 0.75rem; color: var(--color-gold);">Total: ${totalFamCount} alumno(s)</span>
                </div>
            `;

            famItems.forEach(sub => {
                const subRow = document.createElement('div');
                subRow.className = 'top-subject-item';
                subRow.style.margin = '0.35rem 0';
                subRow.innerHTML = `
                    <span class="top-name" style="flex: 1;">${esc(sub.name)}</span>
                    <span class="top-count">${sub.count} alumno(s)</span>
                `;
                famBlock.appendChild(subRow);
            });

            resultsContainer.appendChild(famBlock);
        });
    }

    function updateMesaSubjectOptions() {
        const picker = document.getElementById('mesaSubjectPickerContainer');
        const hiddenInput = document.getElementById('mesaSubjectSelect');
        const statusBadge = document.getElementById('mesaSubjectStatusBadge');
        const specialtySelect = document.getElementById('mesaSpecialtySelect');

        if (!picker || !hiddenInput) return;

        const specialty = specialtySelect ? specialtySelect.value : '';
        const needsSpecialty = selectedMesaCourses.some(c => ['4to', '5to', '6to', '7mo'].includes(c));

        if (selectedMesaCourses.length === 0) {
            selectedMesaSubjects = [];
            hiddenInput.value = '';
            if (statusBadge) statusBadge.style.display = 'none';
            picker.innerHTML = `
                <div class="picker-placeholder">
                    <span class="material-symbols-outlined" style="font-size: 1.3rem;">info</span>
                    Selecciona al menos un curso arriba para ver las materias disponibles.
                </div>
            `;
            return;
        }

        if (needsSpecialty && !specialty) {
            selectedMesaSubjects = [];
            hiddenInput.value = '';
            if (statusBadge) statusBadge.style.display = 'none';
            picker.innerHTML = `
                <div class="picker-placeholder">
                    <span class="material-symbols-outlined" style="font-size: 1.3rem;">engineering</span>
                    Selecciona la Tecnicatura / Especialidad para ver las materias disponibles.
                </div>
            `;
            return;
        }

        const turnSelect = document.getElementById('mesaTurnSelect');
        const selectedTurn = turnSelect ? turnSelect.value : '';
        const enrolledData = getEnrolledCoursesAndSubjects(selectedTurn);

        // Obtener la lista de materias de cada curso seleccionado (filtrando solo las que tengan alumnos inscriptos)
        const coursesSubjectsMap = {};
        selectedMesaCourses.forEach(c => {
            let key = c;
            if (c === '1ro') key = '1er AÑO';
            else if (c === '2do') key = '2do AÑO';
            else if (c === '3ro') key = '3er AÑO';
            else if (['4to', '5to', '6to', '7mo'].includes(c)) {
                key = `${c} ${specialty}`;
            }
            const allSubs = listado_materias[key] || [];
            let courseEnrolled = new Set();
            if (enrolledData[c]) {
                if (['4to', '5to', '6to', '7mo'].includes(c) && specialty && enrolledData[c].bySpecialty && enrolledData[c].bySpecialty[specialty] && enrolledData[c].bySpecialty[specialty].size > 0) {
                    courseEnrolled = enrolledData[c].bySpecialty[specialty];
                } else {
                    courseEnrolled = enrolledData[c].subjects;
                }
            }
            coursesSubjectsMap[c] = allSubs.filter(s => {
                const isEnrolled = courseEnrolled.has(s.toLowerCase()) || courseEnrolled.has(normalizeSubjectName(s));
                return isEnrolled || (editingMesaId && selectedMesaSubjects.includes(s));
            });
        });

        picker.innerHTML = '';

        if (selectedMesaCourses.length === 1) {
            // Un solo curso seleccionado: listar todas sus materias con alumnos inscriptos
            const singleCourse = selectedMesaCourses[0];
            const subjects = coursesSubjectsMap[singleCourse] || [];

            if (subjects.length === 0) {
                picker.innerHTML = `
                    <div class="picker-placeholder" style="color: #fca5a5;">
                        <span class="material-symbols-outlined" style="font-size: 1.3rem;">info</span>
                        No hay materias con alumnos inscriptos en ${esc(getCourseLabel(singleCourse))} para el turno seleccionado.
                    </div>
                `;
                selectedMesaSubjects = [];
                hiddenInput.value = '';
                if (statusBadge) statusBadge.style.display = 'none';
                return;
            }

            const groupEl = document.createElement('div');
            groupEl.className = 'mesa-subject-group';
            groupEl.innerHTML = `<div class="mesa-subject-group-title"><span class="material-symbols-outlined" style="font-size: 1rem;">menu_book</span> Materias de ${esc(getCourseLabel(singleCourse))} (materias con alumnos inscriptos)</div>`;

            subjects.forEach(sub => {
                const itemEl = document.createElement('div');
                itemEl.className = 'mesa-subject-item';
                if (selectedMesaSubjects.includes(sub)) itemEl.classList.add('is-selected');

                const fam = getSubjectFamily(sub);
                itemEl.innerHTML = `
                    <div class="mesa-subject-label">
                        <span class="material-symbols-outlined" style="font-size: 1.1rem; color: var(--color-gold);" data-type="radio">
                            ${selectedMesaSubjects.includes(sub) ? 'check_circle' : 'radio_button_unchecked'}
                        </span>
                        <span>${esc(sub)}</span>
                    </div>
                `;

                itemEl.addEventListener('click', () => {
                    handleSubjectToggle(sub);
                });

                groupEl.appendChild(itemEl);
            });

            picker.appendChild(groupEl);
        } else {
            // Múltiples cursos seleccionados (2 o 3 cursos):
            // Solo se muestran materias que realmente pertenezcan a TODOS los cursos seleccionados:
            // 1. Materias en Común: la misma asignatura exacta está en el plan de estudios y con inscriptos en TODOS los cursos elegidos.
            // 2. Familias correlativas/numeradas por año (ej: Matemática en 4to, Matemática I en 5to, Matemática II en 6to):
            //    solo se habilitan si TODOS los cursos elegidos tienen su materia correspondiente de esa familia.

            const byNormName = {}; // normName -> { displayName, courses: [], isNumbered, family }
            selectedMesaCourses.forEach(c => {
                (coursesSubjectsMap[c] || []).forEach(sub => {
                    // Asegurar que la materia exista realmente en el plan de estudios de ese curso
                    if (!courseHasSubjectInPlan(c, specialty, sub)) return;
                    const norm = normalizeSubjectName(sub);
                    if (!byNormName[norm]) {
                        byNormName[norm] = {
                            displayName: sub,
                            courses: [],
                            isNumbered: isNumberedSubject(sub),
                            family: getSubjectFamily(sub)
                        };
                    }
                    if (!byNormName[norm].courses.includes(c)) {
                        byNormName[norm].courses.push(c);
                    }
                });
            });

            const directCommonSubjects = [];
            const commonNormSet = new Set();

            Object.keys(byNormName).forEach(norm => {
                const entry = byNormName[norm];
                // Una materia es "en común" únicamente si pertenece a TODOS los cursos seleccionados
                const inAllSelectedCourses = selectedMesaCourses.every(c =>
                    entry.courses.includes(c) && courseHasSubjectInPlan(c, specialty, entry.displayName)
                );
                if (inAllSelectedCourses) {
                    directCommonSubjects.push(entry.displayName);
                    commonNormSet.add(norm);
                }
            });

            directCommonSubjects.sort((a, b) => a.localeCompare(b));

            // Familias de materias correlativas/numeradas (ej: Programación I en 5to y Programación II en 6to):
            // Solo se muestran si CADA UNO de los cursos seleccionados tiene una materia de esa familia en su plan e inscriptos.
            const allFamilies = new Set();
            selectedMesaCourses.forEach(c => {
                (coursesSubjectsMap[c] || []).forEach(s => {
                    if (!courseHasSubjectInPlan(c, specialty, s)) return;
                    const fam = getSubjectFamily(s);
                    if (fam) allFamilies.add(fam);
                });
            });

            const commonFamiliesMap = {}; // familyName -> [ { course, subjectName } ]
            allFamilies.forEach(fam => {
                const coursesCovered = new Set();
                const itemsForFam = [];
                selectedMesaCourses.forEach(c => {
                    (coursesSubjectsMap[c] || []).forEach(s => {
                        if (!courseHasSubjectInPlan(c, specialty, s)) return;
                        const norm = normalizeSubjectName(s);
                        if (commonNormSet.has(norm)) return;
                        if (getSubjectFamily(s) === fam) {
                            coursesCovered.add(c);
                            itemsForFam.push({ course: c, subjectName: s });
                        }
                    });
                });

                // Solo habilitar la familia si TODOS los cursos seleccionados tienen su materia correlativa en ella
                if (coursesCovered.size === selectedMesaCourses.length && itemsForFam.length >= selectedMesaCourses.length) {
                    itemsForFam.sort((a, b) => {
                        const ordDiff = getRomanNumeralOrder(a.subjectName) - getRomanNumeralOrder(b.subjectName);
                        return ordDiff !== 0 ? ordDiff : a.subjectName.localeCompare(b.subjectName);
                    });
                    commonFamiliesMap[fam] = itemsForFam;
                }
            });

            const familyKeys = Object.keys(commonFamiliesMap);

            if (directCommonSubjects.length === 0 && familyKeys.length === 0) {
                picker.innerHTML = `
                    <div class="picker-placeholder" style="color: #fca5a5;">
                        <span class="material-symbols-outlined" style="font-size: 1.3rem;">warning</span>
                        Los cursos seleccionados (${esc(selectedMesaCourses.map(c => getCourseLabel(c)).join(', '))}) no comparten materias en común ni familias de materias.<br>
                        Por favor selecciona cursos que compartan contenidos curriculares.
                    </div>
                `;
                selectedMesaSubjects = [];
                hiddenInput.value = '';
                if (statusBadge) statusBadge.style.display = 'none';
                return;
            }

            // 1. Renderizar Materias en Común (una sola vez por nombre)
            if (directCommonSubjects.length > 0) {
                const groupEl = document.createElement('div');
                groupEl.className = 'mesa-subject-group';
                groupEl.innerHTML = `
                    <div class="mesa-subject-group-title">
                        <span class="material-symbols-outlined" style="font-size: 1rem;">checklist</span>
                        Materias en Común
                    </div>
                `;

                directCommonSubjects.forEach(sub => {
                    const itemEl = document.createElement('div');
                    itemEl.className = 'mesa-subject-item';
                    if (selectedMesaSubjects.includes(sub)) itemEl.classList.add('is-selected');

                    itemEl.innerHTML = `
                        <div class="mesa-subject-label">
                            <span class="material-symbols-outlined" style="font-size: 1.1rem; color: var(--color-gold);" data-type="radio">
                                ${selectedMesaSubjects.includes(sub) ? 'check_circle' : 'radio_button_unchecked'}
                            </span>
                            <span>${esc(sub)}</span>
                        </div>
                        <span class="mesa-subject-tag">Común</span>
                    `;

                    itemEl.addEventListener('click', () => {
                        handleSubjectToggle(sub);
                    });

                    groupEl.appendChild(itemEl);
                });

                picker.appendChild(groupEl);
            }

            // 2. Renderizar materias numeradas (I, II, III) o variantes aparte con su curso (1er AÑO, 2do AÑO, 3er AÑO...)
            familyKeys.forEach(fam => {
                const groupEl = document.createElement('div');
                groupEl.className = 'mesa-subject-group';
                groupEl.innerHTML = `
                    <div class="mesa-subject-group-title">
                        <span class="material-symbols-outlined" style="font-size: 1rem;">hub</span>
                        ${esc(fam)} <span style="font-size: 0.73rem; opacity: 0.85; text-transform: none; font-weight: 500;">(Materias numeradas / por año)</span>
                    </div>
                `;

                const items = commonFamiliesMap[fam];
                items.forEach(it => {
                    const itemEl = document.createElement('div');
                    itemEl.className = 'mesa-subject-item';
                    if (selectedMesaSubjects.includes(it.subjectName)) itemEl.classList.add('is-selected');

                    itemEl.innerHTML = `
                        <div class="mesa-subject-label">
                            <span class="material-symbols-outlined" style="font-size: 1.1rem; color: var(--color-gold);" data-type="checkbox">
                                ${selectedMesaSubjects.includes(it.subjectName) ? 'check_box' : 'check_box_outline_blank'}
                            </span>
                            <span>${esc(it.subjectName)}</span>
                        </div>
                        <span class="mesa-subject-tag">${esc(getCourseLabel(it.course))}</span>
                    `;

                    const allowedNames = items.map(x => x.subjectName);
                    directCommonSubjects.forEach(dc => {
                        if (getSubjectFamily(dc) === fam) allowedNames.push(dc);
                    });

                    itemEl.addEventListener('click', () => {
                        handleSubjectToggle(it.subjectName);
                    });

                    groupEl.appendChild(itemEl);
                });

                picker.appendChild(groupEl);
            });
        }

        syncSubjectSelectionUI();
    }

    function areSubjectsAllowedTogether(subjects) {
        if (!subjects || subjects.length <= 1) return true;
        const firstFamily = getSubjectFamily(subjects[0]);
        if (!firstFamily) return false;
        return subjects.every(s => getSubjectFamily(s) === firstFamily);
    }

    function handleSubjectToggle(subjectName) {
        if (selectedMesaSubjects.includes(subjectName)) {
            selectedMesaSubjects = selectedMesaSubjects.filter(s => s !== subjectName);
        } else {
            const candidate = [...selectedMesaSubjects, subjectName];
            if (areSubjectsAllowedTogether(candidate)) {
                selectedMesaSubjects.push(subjectName);
            } else {
                selectedMesaSubjects = [subjectName];
            }
        }
        syncSubjectSelectionUI();
    }

    function handleFamilySubjectClick(subjectName, familyName, allowedFamilySubjects) {
        handleSubjectToggle(subjectName);
    }

    function handleCommonSubjectClick(subjectName) {
        handleSubjectToggle(subjectName);
    }

    function syncSubjectSelectionUI() {
        const picker = document.getElementById('mesaSubjectPickerContainer');
        const hiddenInput = document.getElementById('mesaSubjectSelect');
        const statusBadge = document.getElementById('mesaSubjectStatusBadge');

        if (!picker || !hiddenInput) return;

        hiddenInput.value = selectedMesaSubjects.join(', ');

        if (selectedMesaSubjects.length > 0) {
            picker.classList.remove('field-error-warning');
            const parent = picker.parentElement;
            if (parent) {
                const msg = parent.querySelector('.field-error-msg');
                if (msg) msg.remove();
            }
            if (statusBadge) {
                statusBadge.style.display = 'inline-block';
                statusBadge.textContent = `${selectedMesaSubjects.length} seleccionada(s)`;
            }
        } else {
            if (statusBadge) statusBadge.style.display = 'none';
        }

        const items = picker.querySelectorAll('.mesa-subject-item');
        items.forEach(it => {
            const labelText = it.querySelector('.mesa-subject-label span:last-child')?.textContent?.trim();
            const icon = it.querySelector('.mesa-subject-label span:first-child');
            const isSelected = selectedMesaSubjects.includes(labelText);
            it.classList.toggle('is-selected', isSelected);

            if (icon) {
                if (icon.dataset.type === 'radio' || icon.textContent.includes('radio_button') || icon.textContent.includes('check_circle')) {
                    icon.textContent = isSelected ? 'check_circle' : 'radio_button_unchecked';
                } else {
                    icon.textContent = isSelected ? 'check_box' : 'check_box_outline_blank';
                }
            }
        });
    }

    function updateMesaDateConstraints() {
        const turnSelect = document.getElementById('mesaTurnSelect');
        const dateInput = document.getElementById('mesaDateInput');
        const dateHint = document.getElementById('mesaDateHint');
        if (!turnSelect || !dateInput) return;

        const turn = turnSelect.value;
        const now = new Date();
        const currentYear = now.getFullYear();
        const currentMonth = now.getMonth(); // 0 a 11
        const todayStr = now.toISOString().split('T')[0];
        const maxAllowed = '2031-12-31';

        let targetYear = Math.max(currentYear, 2026);

        if (turn === 'Febrero / Marzo') {
            if (currentYear === targetYear && currentMonth > 2) {
                targetYear = Math.min(targetYear + 1, 2031);
            }
            let turnMin = `${targetYear}-02-01`;
            let turnMax = `${targetYear}-03-31`;
            if (turnMin < todayStr) turnMin = todayStr;
            if (turnMax > maxAllowed) turnMax = maxAllowed;

            dateInput.min = turnMin;
            dateInput.max = turnMax;
            if (dateHint) dateHint.textContent = '';
            if (!dateInput.value || dateInput.value < turnMin || dateInput.value > turnMax) {
                dateInput.value = turnMin;
            }
        } else if (turn === 'Julio / Agosto' || turn === 'Julio') {
            if (currentYear === targetYear && currentMonth > 7) {
                targetYear = Math.min(targetYear + 1, 2031);
            }
            let turnMin = `${targetYear}-07-01`;
            let turnMax = `${targetYear}-08-31`;
            if (turnMin < todayStr) turnMin = todayStr;
            if (turnMax > maxAllowed) turnMax = maxAllowed;

            dateInput.min = turnMin;
            dateInput.max = turnMax;
            if (dateHint) dateHint.textContent = '';
            if (!dateInput.value || dateInput.value < turnMin || dateInput.value > turnMax) {
                dateInput.value = turnMin;
            }
        } else if (turn === 'Noviembre / Diciembre' || turn === 'Diciembre') {
            if (currentYear === targetYear && currentMonth > 11) {
                targetYear = Math.min(targetYear + 1, 2031);
            }
            let turnMin = `${targetYear}-11-01`;
            let turnMax = `${targetYear}-12-31`;
            if (turnMin < todayStr) turnMin = todayStr;
            if (turnMax > maxAllowed) turnMax = maxAllowed;

            dateInput.min = turnMin;
            dateInput.max = turnMax;
            if (dateHint) dateHint.textContent = '';
            if (!dateInput.value || dateInput.value < turnMin || dateInput.value > turnMax) {
                dateInput.value = turnMin;
            }
        }
    }

    function startEditMesa(mesaId) {
        const mesas = DB.getMesas();
        const mesa = mesas.find(m => m.id === mesaId);
        if (!mesa) return;

        editingMesaId = mesaId;

        // Recuperar cursos seleccionados
        let coursesArr = [];
        if (mesa.courses && Array.isArray(mesa.courses) && mesa.courses.length > 0) {
            coursesArr = [...mesa.courses];
        } else if (mesa.courseRaw) {
            coursesArr = mesa.courseRaw.split(',').map(s => s.trim()).filter(Boolean);
        } else if (mesa.course) {
            const matches = mesa.course.match(/(1ro|2do|3ro|4to|5to|6to|7mo)/g);
            if (matches) coursesArr = [...new Set(matches)];
        }

        selectedMesaCourses = coursesArr.filter(c => c !== 'Egreso' && c !== 'Egresado');

        let specialtyVal = mesa.specialty || '';
        if (!specialtyVal && mesa.course) {
            if (mesa.course.includes('TAE')) specialtyVal = 'TAE';
            else if (mesa.course.includes('TC')) specialtyVal = 'TC';
            else if (mesa.course.includes('TG')) specialtyVal = 'TG';
        }

        const specialtySelect = document.getElementById('mesaSpecialtySelect');
        if (specialtySelect) {
            specialtySelect.value = specialtyVal;
        }

        const turnSelect = document.getElementById('mesaTurnSelect');
        if (turnSelect) {
            turnSelect.value = mesa.examTurn;
            updateMesaDateConstraints();
            if (turnSelect._refreshCustomOptions) turnSelect._refreshCustomOptions();
        }

        updateMesaCourseChipsUI();
        updateMesaSubjectOptions();

        // Recuperar materias seleccionadas
        if (mesa.subjectsArray && Array.isArray(mesa.subjectsArray) && mesa.subjectsArray.length > 0) {
            selectedMesaSubjects = [...mesa.subjectsArray];
        } else if (mesa.subjectName) {
            selectedMesaSubjects = mesa.subjectName.split(',').map(s => s.trim()).filter(Boolean);
        }
        syncSubjectSelectionUI();

        const dateInput = document.getElementById('mesaDateInput');
        const timeInput = document.getElementById('mesaTimeInput');
        const classroomInput = document.getElementById('mesaClassroomInput');
        const presInput = document.getElementById('mesaPresDocente');
        const v1Input = document.getElementById('mesaVocal1Docente');
        const v2Input = document.getElementById('mesaVocal2Docente');
        const btnSubmitText = document.getElementById('btnSubmitMesaText');
        const btnSubmitIcon = document.getElementById('btnSubmitMesaIcon');
        const btnCancel = document.getElementById('btnCancelEditMesa');

        if (dateInput) dateInput.value = mesa.examDate || '';
        if (timeInput) timeInput.value = mesa.examTime || '08:00';
        if (classroomInput) {
            let cleanRoom = (mesa.classroom === 'Por determinar' ? '' : (mesa.classroom || '')).replace(/^Aula\s+/i, '').trim();
            classroomInput.value = cleanRoom;
            if (classroomInput._refreshCustomOptions) classroomInput._refreshCustomOptions();
        }

        let pres = mesa.presDocente || '';
        let v1 = mesa.v1Docente || '';
        let v2 = mesa.v2Docente || '';
        if (!pres && mesa.tribunal) {
            const parts = mesa.tribunal.split(',').map(s => s.trim());
            pres = parts[0] || '';
            v1 = parts[1] || '';
            v2 = parts[2] || '';
        }

        if (presInput) presInput.value = pres;
        if (v1Input) v1Input.value = v1;
        if (v2Input) v2Input.value = v2;

        if (btnSubmitText) btnSubmitText.textContent = 'Guardar Cambios';
        if (btnSubmitIcon) btnSubmitIcon.textContent = 'save';
        if (btnCancel) btnCancel.style.display = 'inline-flex';

        const formBlock = document.getElementById('formCreateMesa');
        if (formBlock) {
            formBlock.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function cancelEditMesa() {
        editingMesaId = null;
        const form = document.getElementById('formCreateMesa');
        if (form) form.reset();

        selectedMesaCourses = [];
        selectedMesaSubjects = [];

        updateMesaCourseChipsUI();
        updateMesaSubjectOptions();
        updateMesaDateConstraints();

        const btnSubmitText = document.getElementById('btnSubmitMesaText');
        const btnSubmitIcon = document.getElementById('btnSubmitMesaIcon');
        const btnCancel = document.getElementById('btnCancelEditMesa');

        if (btnSubmitText) btnSubmitText.textContent = 'Crear Mesa de Examen';
        if (btnSubmitIcon) btnSubmitIcon.textContent = 'event_available';
        if (btnCancel) btnCancel.style.display = 'none';

        const turnSelect = document.getElementById('mesaTurnSelect');
        if (turnSelect) {
            turnSelect.value = typeof getCurrentExamTurn === 'function' ? getCurrentExamTurn() : 'Febrero / Marzo';
            if (turnSelect._refreshCustomOptions) turnSelect._refreshCustomOptions();
        }
        const classroomInput = document.getElementById('mesaClassroomInput');
        if (classroomInput && classroomInput._refreshCustomOptions) classroomInput._refreshCustomOptions();
    }

    function handleCreateMesa() {
        clearFieldErrors();

        const chipsGrid = document.getElementById('mesaCourseChipsGrid');
        const picker = document.getElementById('mesaSubjectPickerContainer');
        const specialtySelect = document.getElementById('mesaSpecialtySelect');
        const turnSelect = document.getElementById('mesaTurnSelect');
        const dateInput = document.getElementById('mesaDateInput');
        const timeInput = document.getElementById('mesaTimeInput');
        const classroomInput = document.getElementById('mesaClassroomInput');

        if (selectedMesaCourses.length === 0) {
            highlightFieldError(chipsGrid, "Debes seleccionar al menos un curso (hasta 3)");
            return;
        }

        const hasBasico = selectedMesaCourses.some(c => ['1ro', '2do', '3ro'].includes(c));
        const hasSuperior = selectedMesaCourses.some(c => ['4to', '5to', '6to', '7mo'].includes(c));
        const needsSpecialty = hasSuperior;

        if (hasBasico && hasSuperior) {
            highlightFieldError(chipsGrid, "No se pueden combinar cursos de Ciclo Básico con Ciclo Superior.");
            return;
        }

        const specialty = specialtySelect ? specialtySelect.value : '';
        if (hasSuperior && !specialty) {
            highlightFieldError(specialtySelect, "Debes seleccionar la Tecnicatura (TAE, TC o TG)");
            return;
        }

        if (selectedMesaSubjects.length === 0) {
            highlightFieldError(picker, "Debes seleccionar al menos una materia para la mesa");
            return;
        }

        if (selectedMesaSubjects.length > 1 && !areSubjectsAllowedTogether(selectedMesaSubjects)) {
            highlightFieldError(picker, "No se puede elegir más de una materia para una mesa de examen, salvo que sean materias correlativas numeradas de la misma familia (ej: Matemática, Matemática I).");
            return;
        }

        // Validar que cada curso elegido tenga asignada al menos una de las materias seleccionadas en su plan de estudios
        const invalidCourses = selectedMesaCourses.filter(c =>
            !selectedMesaSubjects.some(sub => courseHasSubjectInPlan(c, needsSpecialty ? specialty : '', sub))
        );
        if (invalidCourses.length > 0) {
            const invalidLabels = invalidCourses.map(c => getCourseLabel(c)).join(', ');
            highlightFieldError(
                picker,
                `La materia seleccionada (${selectedMesaSubjects.join(', ')}) no pertenece al plan de estudios de ${invalidLabels}. Quitá ese curso o seleccioná la materia correlativa correspondiente a cada año elegido.`
            );
            return;
        }

        const examTurn = turnSelect.value;
        const examDate = dateInput.value;
        const examTime = timeInput.value;
        const classroom = classroomInput.value.trim();

        if (!examTurn) {
            highlightFieldError(turnSelect);
            return;
        }

        if (!examDate) {
            highlightFieldError(dateInput);
            return;
        }

        const todayStr = new Date().toISOString().split('T')[0];
        const maxAllowed = '2031-12-31';

        if (examDate < todayStr || examDate > maxAllowed) {
            highlightFieldError(dateInput, `La fecha del examen debe ser desde hoy (${todayStr}) hasta el 31/12/2031.`);
            return;
        }

        if ((dateInput.min && examDate < dateInput.min) || (dateInput.max && examDate > dateInput.max)) {
            highlightFieldError(dateInput, `Solo puedes seleccionar una fecha dentro del período de ${examTurn}`);
            return;
        }

        if (!examTime || examTime < '07:00' || examTime > '19:00') {
            highlightFieldError(timeInput, "La hora de inicio debe estar entre las 07:00 y las 19:00 hs.");
            return;
        }

        // Lectura y validación estricta de los 3 integrantes del Tribunal Docente (Obligatorio Nombre y Apellido, sin números)
        const presInput = document.getElementById('mesaPresDocente');
        const v1Input = document.getElementById('mesaVocal1Docente');
        const v2Input = document.getElementById('mesaVocal2Docente');

        function validateDocenteName(inputEl, roleName) {
            if (!inputEl) return false;
            const val = inputEl.value.trim();
            if (!val) {
                highlightFieldError(inputEl, `Debes ingresar el Nombre y Apellido del ${roleName}`);
                return false;
            }
            if (/\d/.test(val)) {
                highlightFieldError(inputEl, `El ${roleName} no puede contener números, solo letras`);
                return false;
            }
            const words = val.split(/\s+/).filter(w => w.length > 0);
            if (words.length < 2) {
                highlightFieldError(inputEl, `Debes ingresar al menos Nombre y Apellido completo para el ${roleName}`);
                return false;
            }
            if (!/^[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s'\-\.]+$/.test(val)) {
                highlightFieldError(inputEl, `El ${roleName} contiene caracteres no válidos`);
                return false;
            }
            return true;
        }

        if (!validateDocenteName(presInput, "Presidente de Mesa")) return;
        if (!validateDocenteName(v1Input, "1° Vocal")) return;
        if (!validateDocenteName(v2Input, "2° Vocal")) return;

        const presDocente = presInput.value.trim();
        const v1Docente = v1Input.value.trim();
        const v2Docente = v2Input.value.trim();

        const tribunal = `${presDocente}, ${v1Docente}, ${v2Docente}`;

        const formattedCourseLabels = selectedMesaCourses.map(c => {
            if (c === '1ro') return '1er AÑO';
            if (c === '2do') return '2do AÑO';
            if (c === '3ro') return '3er AÑO';
            return c;
        });
        let courseFormatted = formattedCourseLabels.join(', ');
        if (specialty && needsSpecialty) {
            courseFormatted += ` (${specialty})`;
        }

        const subjectName = selectedMesaSubjects.join(', ');

        const mesaPayload = {
            course: courseFormatted,
            courses: [...selectedMesaCourses],
            courseRaw: formattedCourseLabels.join(', '),
            specialty: needsSpecialty ? specialty : '',
            subjectName,
            subjectsArray: [...selectedMesaSubjects],
            examTurn,
            examDate,
            examTime,
            classroom: classroom || 'Por determinar',
            tribunal,
            presDocente,
            v1Docente,
            v2Docente
        };

        if (editingMesaId) {
            const updatedId = editingMesaId;
            DB.updateMesa(editingMesaId, mesaPayload);
            cancelEditMesa();
            const selectMesa = document.getElementById('selectMesaListado');
            if (selectMesa) selectMesa.value = updatedId;
            renderMesasTab();
            renderListadosTab();
            alert("¡Mesa de examen actualizada exitosamente!");
        } else {
            const newMesa = DB.createMesa(mesaPayload);
            document.getElementById('formCreateMesa').reset();
            selectedMesaCourses = [];
            selectedMesaSubjects = [];
            if (turnSelect) {
                turnSelect.value = typeof getCurrentExamTurn === 'function' ? getCurrentExamTurn() : 'Febrero / Marzo';
                updateMesaDateConstraints();
            }
            updateMesaCourseChipsUI();
            updateMesaSubjectOptions();
            if (turnSelect && turnSelect._refreshCustomOptions) turnSelect._refreshCustomOptions();
            if (classroomInput && classroomInput._refreshCustomOptions) classroomInput._refreshCustomOptions();
            const selectMesa = document.getElementById('selectMesaListado');
            if (selectMesa && newMesa && newMesa.id) {
                selectMesa.value = newMesa.id;
            }
            renderMesasTab();
            renderListadosTab();
            alert("¡Mesa de examen creada exitosamente!");
        }
    }

    // Busca todos los alumnos inscriptos que corresponden a una mesa de examen determinada
    function getStudentsForMesa(mesa) {
        if (!mesa) return [];
        const enrollments = DB.getAll();
        const mesaSubjNorms = (mesa.subjectsArray && Array.isArray(mesa.subjectsArray) && mesa.subjectsArray.length > 0)
            ? mesa.subjectsArray.map(s => normalizeSubjectName(s))
            : String(mesa.subjectName || '').split(',').map(s => normalizeSubjectName(s)).filter(Boolean);

        let mesaSpecialty = (mesa.specialty || '').trim().toUpperCase();
        if (!mesaSpecialty && mesa.course) {
            if (mesa.course.includes('TAE')) mesaSpecialty = 'TAE';
            else if (mesa.course.includes('TC')) mesaSpecialty = 'TC';
            else if (mesa.course.includes('TG')) mesaSpecialty = 'TG';
        }

        const mesaCourseNums = (String(mesa.course || '').match(/\d+/g) || []);

        function matchesSubjectAndCourse(rec, sub) {
            const subNorm = normalizeSubjectName(sub.subjectName || '');
            if (!subNorm) return false;
            const matchesSub = mesaSubjNorms.some(ms => ms === subNorm || ms.includes(subNorm) || subNorm.includes(ms));
            if (!matchesSub) return false;

            if (mesaCourseNums.length > 0) {
                const rawYear = String(sub.owedYear || sub.course || rec.currentCourse || '');
                const subNums = rawYear.match(/\d+/g) || [];
                if (subNums.length > 0 && !subNums.some(n => mesaCourseNums.includes(n))) {
                    return false;
                }
            }

            if (mesaSpecialty) {
                const rawSpec = String((sub.owedYear || '') + ' ' + (sub.course || '') + ' ' + (rec.currentSpecialty || '') + ' ' + (rec.currentCourse || '')).toUpperCase();
                if (rawSpec.includes('TAE') || rawSpec.includes('TC') || rawSpec.includes('TG')) {
                    if (!rawSpec.includes(mesaSpecialty)) return false;
                }
            }
            return true;
        }

        const exactTurnStudents = [];
        const fallbackTurnStudents = [];

        enrollments.forEach(rec => {
            if (!rec.subjects || !Array.isArray(rec.subjects)) return;
            rec.subjects.forEach(sub => {
                if (!matchesSubjectAndCourse(rec, sub)) return;
                const studentEntry = {
                    studentName: rec.studentName,
                    studentDni: rec.studentDni,
                    paid: !!(sub.paid === true || sub.paid === 1 || sub.paid === '1' || (sub.paid === undefined && (rec.paid === true || rec.paid === 1 || rec.paid === '1')))
                };
                if (sub.examTurn === mesa.examTurn) {
                    if (!exactTurnStudents.some(s => s.studentDni === rec.studentDni)) {
                        exactTurnStudents.push(studentEntry);
                    }
                } else {
                    if (!fallbackTurnStudents.some(s => s.studentDni === rec.studentDni)) {
                        fallbackTurnStudents.push(studentEntry);
                    }
                }
            });
        });

        return exactTurnStudents.length > 0 ? exactTurnStudents : fallbackTurnStudents;
    }

    function renderMesasTab() {
        const mesas = DB.getMesas();
        const tbody = document.getElementById('mesasTableBody');

        if (!tbody) return;
        tbody.innerHTML = '';

        if (mesas.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: #888; padding: 1.5rem;">No hay mesas de examen creadas.</td></tr>';
            return;
        }

        mesas.forEach(m => {
            const inscriptosCount = getStudentsForMesa(m).length;

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><strong>${esc(m.course)}</strong></td>
                <td><strong>${esc(m.subjectName)}</strong></td>
                <td>${esc(m.examTurn)}</td>
                <td>${esc(m.examDate)} (${esc(m.examTime)} hs)</td>
                <td>${esc(m.classroom)}</td>
                <td><span class="badge-count">${inscriptosCount} alumno(s)</span></td>
                <td>
                    <div style="display: flex; gap: 6px; align-items: center; justify-content: center;">
                        <button type="button" class="btn-action-edit" data-id="${esc(m.id)}" title="Editar mesa">
                            <span class="material-symbols-outlined">edit</span>
                        </button>
                        <button type="button" class="btn-action-delete" data-id="${esc(m.id)}" title="Eliminar mesa">
                            <span class="material-symbols-outlined">delete</span>
                        </button>
                    </div>
                </td>
            `;

            tr.querySelector('.btn-action-edit').addEventListener('click', () => {
                startEditMesa(m.id);
            });

            tr.querySelector('.btn-action-delete').addEventListener('click', () => {
                if (confirm(`¿Eliminar la mesa de examen de ${m.subjectName}?`)) {
                    if (editingMesaId === m.id) {
                        cancelEditMesa();
                    }
                    DB.deleteMesa(m.id);
                    renderMesasTab();
                    renderListadosTab();
                }
            });

            tbody.appendChild(tr);
        });
    }

    function renderAbonosTab() {
        const courseFilter = document.getElementById('filterCourseAbono')?.value || '';
        const turnFilter = document.getElementById('filterTurnAbono')?.value || (typeof getCurrentExamTurn === 'function' ? getCurrentExamTurn() : 'Febrero / Marzo');
        const statusFilter = document.getElementById('filterStatusAbono')?.value || '';
        const searchVal = document.getElementById('searchAbonoInput')?.value.toLowerCase() || '';

        const records = DB.getFilteredEnrollments({
            course: courseFilter,
            examTurn: turnFilter,
            paymentStatus: statusFilter,
            subject: searchVal
        });

        const countBadge = document.getElementById('abonosFilterCount');
        if (countBadge) countBadge.textContent = `${records.length} alumno(s)`;

        const tbody = document.getElementById('abonosTableBody');
        if (!tbody) return;
        tbody.innerHTML = '';

        if (records.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: #888; padding: 1.5rem;">No se encontraron registros con los filtros seleccionados.</td></tr>';
            return;
        }

        records.forEach(r => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><strong>${esc(r.studentName)}</strong><br><small style="color:#aaa;">DNI: ${esc(r.studentDni)}</small></td>
                <td><strong>${esc(r.owedYear)}</strong></td>
                <td>${esc(r.subjectName)}</td>
                <td>${esc(r.examTurn)}</td>
                <td>
                    <span class="status-badge ${r.paid ? 'paid' : 'unpaid'}">
                        <span class="material-symbols-outlined">${r.paid ? 'check_circle' : 'pending'}</span>
                        ${r.paid ? 'ABONADO' : 'PENDIENTE'}
                    </span>
                </td>
                <td>
                    <button type="button" class="btn-toggle-pay ${r.paid ? 'cancel' : 'pay'}" data-enrollment="${esc(r.enrollmentId)}" data-subject="${esc(r.subjectId)}">
                        ${r.paid ? 'Marcar Pendiente' : 'Registrar Pago'}
                    </button>
                </td>
            `;

            tr.querySelector('.btn-toggle-pay').addEventListener('click', () => {
                DB.updatePaymentStatus(r.enrollmentId, r.subjectId, !r.paid);
                populateAbonoFilters();
                renderAbonosTab();
                renderStatsTab();
            });

            tbody.appendChild(tr);
        });
    }

    function renderListadosTab() {
        const selectMesa = document.getElementById('selectMesaListado');
        const mesas = DB.getMesas();

        if (selectMesa) {
            const currentVal = selectMesa.value;
            selectMesa.innerHTML = '<option value="" disabled>-- Seleccionar Mesa de Examen --</option>';
            mesas.forEach(m => {
                const opt = document.createElement('option');
                opt.value = m.id;
                opt.textContent = `${m.subjectName} - ${m.course} (${m.examTurn})`;
                selectMesa.appendChild(opt);
            });
            if (currentVal && mesas.some(m => m.id === currentVal)) {
                selectMesa.value = currentVal;
            } else if (mesas.length > 0) {
                selectMesa.value = mesas[mesas.length - 1].id;
            }
            if (selectMesa._refreshCustomOptions) {
                selectMesa._refreshCustomOptions();
            }
        }

        const selectedMesaId = selectMesa?.value;
        const selectedMesa = mesas.find(m => m.id === selectedMesaId) || mesas[0];

        const container = document.getElementById('rosterPrintContainer');
        if (!selectedMesa) {
            if (container) container.style.display = 'none';
            return;
        }

        if (container) container.style.display = 'block';

        // Asignar imágenes Base64 si existen
        const escudoImg = document.getElementById('rosterEscudoImg');
        const logoImg = document.getElementById('rosterLogoImg');
        if (escudoImg && window.ESCUDO_BASE64) escudoImg.src = window.ESCUDO_BASE64;
        if (logoImg && window.LOGO_BASE64) logoImg.src = window.LOGO_BASE64;

        // Datos del encabezado y cuadro de fecha
        let dia = '', mes = '', anio = '', hora = '';
        if (selectedMesa.examDate) {
            const parts = selectedMesa.examDate.split('-');
            if (parts.length === 3) {
                anio = parts[0].slice(-2); // Solo '26', sin el 20 adelante
                mes = parts[1];
                dia = parts[2];
            }
        }
        if (selectedMesa.examTime) {
            hora = selectedMesa.examTime; // Solo los números (ej. 08:00 sin el hs)
        }

        const elDia = document.getElementById('rosterFechaDia');
        const elMes = document.getElementById('rosterFechaMes');
        const elAnio = document.getElementById('rosterFechaAnio');
        const elHora = document.getElementById('rosterFechaHora');
        if (elDia) elDia.textContent = dia;
        if (elMes) elMes.textContent = mes;
        if (elAnio) elAnio.textContent = anio;
        if (elHora) elHora.textContent = hora;

        // Determinar División (TC, TAE, TG) o dejar vacío sin puntos suspensivos para 3er año o menor
        let divVal = selectedMesa.specialty || '';
        if (!divVal && selectedMesa.course) {
            if (selectedMesa.course.includes('TAE')) divVal = 'TAE';
            else if (selectedMesa.course.includes('TC')) divVal = 'TC';
            else if (selectedMesa.course.includes('TG')) divVal = 'TG';
        }

        const isJuniorCycle = selectedMesa.courses && selectedMesa.courses.length > 0
            ? selectedMesa.courses.every(c => ['1ro', '2do', '3ro'].includes(c))
            : (selectedMesa.course && !selectedMesa.course.includes('4to') && !selectedMesa.course.includes('5to') && !selectedMesa.course.includes('6to') && !selectedMesa.course.includes('7mo'));

        if (isJuniorCycle) {
            divVal = '';
        }

        // Formatear Año solo como 4°, 5°, etc.
        function formatCourseDegrees(courseStr, coursesArr) {
            if (coursesArr && Array.isArray(coursesArr) && coursesArr.length > 0) {
                return coursesArr.map(c => {
                    const m = c.match(/\d+/);
                    return m ? `${m[0]}°` : c;
                }).join(', ');
            }
            if (!courseStr) return '';
            const cleanStr = courseStr.replace(/\s*\((TAE|TC|TG)\)/i, '');
            const nums = cleanStr.match(/\d+/g);
            if (nums && nums.length > 0) {
                const unique = [...new Set(nums)];
                return unique.map(n => `${n}°`).join(', ');
            }
            return cleanStr;
        }

        const courseDisplay = formatCourseDegrees(selectedMesa.courseRaw || selectedMesa.course, selectedMesa.courses);

        const elSubject = document.getElementById('rosterSubjectName');
        const elCourse = document.getElementById('rosterCourse');
        const elDivision = document.getElementById('rosterDivision');
        const elTurn = document.getElementById('rosterTurn');

        if (elSubject) elSubject.textContent = selectedMesa.subjectName;
        if (elCourse) elCourse.textContent = courseDisplay;
        if (elDivision) elDivision.textContent = divVal;
        if (elTurn) elTurn.textContent = selectedMesa.examTurn;

        const studentsInMesa = getStudentsForMesa(selectedMesa);

        // PÁGINA 1: Renderizar exactamente 30 filas numeradas (del 1 al 30)
        const tbody = document.getElementById('rosterTableBody');
        if (tbody) {
            tbody.innerHTML = '';
            for (let i = 1; i <= 30; i++) {
                const s = studentsInMesa[i - 1];
                const tr = document.createElement('tr');
                tr.style.height = '19.5px';
                tr.innerHTML = `
                    <td style="border: 1px solid #000; text-align: center; font-size: 7.5pt; font-weight: normal; padding: 1px 1px; width: 46px;">${i}</td>
                    <td style="border: 1px solid #000; text-align: center; font-size: 7.5pt; padding: 1px 1px; width: 82px;"></td>
                    <td style="border: 1px solid #000; text-align: center; font-size: 7.5pt; padding: 1px 2px; width: 86px;">${esc(s ? s.studentDni : '')}</td>
                    <td style="border: 1px solid #000; padding: 1px 6px; font-size: 7.5pt; text-align: left;">${esc(s ? s.studentName : '')}</td>
                    <td style="border: 1px solid #000; text-align: center; width: 52px;"></td>
                    <td style="border: 1px solid #000; text-align: center; width: 52px;"></td>
                    <td style="border: 1px solid #000; text-align: center; width: 52px;"></td>
                `;
                tbody.appendChild(tr);
            }
        }

        // PÁGINA 1: Firmas del Tribunal y Aclaraciones
        const presAcl = document.getElementById('rosterPresAcl');
        const v1Acl = document.getElementById('rosterV1Acl');
        const v2Acl = document.getElementById('rosterV2Acl');
        const totalAlumnos = document.getElementById('rosterTotalAlumnos');

        let presName = selectedMesa.presDocente || selectedMesa.pres_docente || '';
        let v1Name = selectedMesa.v1Docente || selectedMesa.v1_docente || '';
        let v2Name = selectedMesa.v2Docente || selectedMesa.v2_docente || '';
        if (!presName && selectedMesa.tribunal) {
            const parts = String(selectedMesa.tribunal)
                .split(/[,|]/)
                .map(p => p.replace(/^(Presidente|Vocal\s*\d*)\s*:\s*/i, '').trim())
                .filter(Boolean);
            presName = parts[0] || '';
            v1Name = parts[1] || '';
            v2Name = parts[2] || '';
        }

        if (presAcl) presAcl.textContent = presName;
        if (v1Acl) v1Acl.textContent = v1Name;
        if (v2Acl) v2Acl.textContent = v2Name;
        if (totalAlumnos) totalAlumnos.textContent = studentsInMesa.length > 0 ? `${studentsInMesa.length}` : '';

        // PÁGINA 2: Renderizar cuadro único del Acta de Desaprobación (4 columnas sin renglones internos como el PDF oficial)
        const desaprobadosTbody = document.getElementById('rosterDesaprobacionTableBody');
        if (desaprobadosTbody) {
            desaprobadosTbody.innerHTML = `
                <tr style="height: 635px;">
                    <td style="border: 1px solid #000; padding: 6px; text-align: left; vertical-align: top; font-size: 8pt;"></td>
                    <td style="border: 1px solid #000; padding: 6px; text-align: left; vertical-align: top; font-size: 8pt;"></td>
                    <td style="border: 1px solid #000; text-align: center; vertical-align: bottom; padding-bottom: 6px;"></td>
                    <td style="border: 1px solid #000; text-align: center; vertical-align: bottom; padding-bottom: 6px;"></td>
                </tr>
            `;
        }
    }

    async function handleDownloadRosterPdf() {
        // Actualizamos primero la vista previa del acta con los datos de la mesa seleccionada
        renderListadosTab();

        const selectMesa = document.getElementById('selectMesaListado');
        const mesas = DB.getMesas();
        const selectedMesa = mesas.find(m => m.id === selectMesa?.value) || mesas[mesas.length - 1];

        if (!selectedMesa) {
            alert("Por favor selecciona una mesa de examen para descargar el acta.");
            return;
        }

        const page1El = document.querySelector('.roster-page-1');
        const page2ContainerEl = document.querySelector('.roster-page-2-container');
        const page2ContentEl = document.querySelector('.roster-page-2-content');

        if (!page1El || !page2ContentEl) return;

        const filename = `Acta_Volante_${selectedMesa.subjectName.replace(/\s+/g, '_')}_${selectedMesa.course.replace(/\s+/g, '_')}.pdf`;

        const btn = document.getElementById('btnDownloadRosterPdf');
        const origText = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-outlined">hourglass_top</span> Generando PDF A4...';
        }

        const escudoImg = document.getElementById('rosterEscudoImg');
        const logoImg = document.getElementById('rosterLogoImg');
        if (escudoImg && window.ESCUDO_BASE64) escudoImg.src = window.ESCUDO_BASE64;
        if (logoImg && window.LOGO_BASE64) logoImg.src = window.LOGO_BASE64;

        try {
            if (window.jspdf && window.html2canvas) {
                // Contenedor temporal ubicado en (0, 0) para que html2canvas no recorte los datos al hacer scroll
                const staging = document.createElement('div');
                staging.id = 'pdfStagingArea';
                staging.style.position = 'fixed';
                staging.style.left = '0';
                staging.style.top = '0';
                staging.style.width = '1400px';
                staging.style.zIndex = '-9999';
                staging.style.pointerEvents = 'none';
                staging.style.background = '#ffffff';
                staging.style.margin = '0';
                staging.style.padding = '0';
                document.body.appendChild(staging);

                // --- PÁGINA 1: A4 VERTICAL EXACTO (794px x 1123px) ---
                const p1 = document.createElement('div');
                p1.style.width = '794px';
                p1.style.minWidth = '794px';
                p1.style.maxWidth = '794px';
                p1.style.height = '1123px';
                p1.style.minHeight = '1123px';
                p1.style.maxHeight = '1123px';
                p1.style.padding = '32px 42px';
                p1.style.boxSizing = 'border-box';
                p1.style.display = 'flex';
                p1.style.flexDirection = 'column';
                p1.style.justifyContent = 'flex-start';
                p1.style.background = '#ffffff';
                p1.style.color = '#000000';
                p1.style.fontFamily = 'Arial, Helvetica, sans-serif';
                p1.innerHTML = page1El.innerHTML;
                staging.appendChild(p1);

                const esc1 = p1.querySelector('#rosterEscudoImg');
                const log1 = p1.querySelector('#rosterLogoImg');
                if (esc1 && window.ESCUDO_BASE64) esc1.src = window.ESCUDO_BASE64;
                if (log1 && window.LOGO_BASE64) log1.src = window.LOGO_BASE64;

                const table1 = p1.querySelector('.roster-main-table');
                if (table1) {
                    table1.style.width = '100%';
                    table1.style.borderCollapse = 'collapse';
                }

                // 1. Capturar Página 1 (A4 Vertical) sin desfase por scroll
                const canvas1 = await window.html2canvas(p1, {
                    scale: 2,
                    useCORS: true,
                    allowTaint: true,
                    logging: false,
                    backgroundColor: '#ffffff',
                    width: 794,
                    height: 1123,
                    x: 0,
                    y: 0,
                    scrollX: 0,
                    scrollY: 0,
                    windowWidth: 1400,
                    windowHeight: 1400
                });

                p1.remove();

                // --- PÁGINA 2: Captura en 1123px x 794px y rotación de 90° en sentido horario hacia A4 Vertical (794px x 1123px) ---
                const p2 = document.createElement('div');
                p2.style.width = '1123px';
                p2.style.minWidth = '1123px';
                p2.style.maxWidth = '1123px';
                p2.style.height = '794px';
                p2.style.minHeight = '794px';
                p2.style.maxHeight = '794px';
                p2.style.padding = '38px 42px';
                p2.style.boxSizing = 'border-box';
                p2.style.display = 'flex';
                p2.style.flexDirection = 'column';
                p2.style.justifyContent = 'flex-start';
                p2.style.background = '#ffffff';
                p2.style.color = '#000000';
                p2.style.fontFamily = 'Arial, Helvetica, sans-serif';
                p2.innerHTML = page2ContentEl.innerHTML;
                staging.appendChild(p2);

                const table2 = p2.querySelector('.roster-desaprobacion-table');
                if (table2) {
                    table2.style.width = '100%';
                    table2.style.borderCollapse = 'collapse';
                    table2.style.flex = '1';
                    const ths = table2.querySelectorAll('thead th');
                    if (ths.length >= 4) {
                        ths[0].style.width = '23%';
                        ths[1].style.width = '49%';
                        ths[2].style.width = '14%';
                        ths[3].style.width = '14%';
                    }
                }

                // 2. Capturar Página 2 en (0, 0) y rotarla 90° en sentido horario
                const canvas2Raw = await window.html2canvas(p2, {
                    scale: 2,
                    useCORS: true,
                    allowTaint: true,
                    logging: false,
                    backgroundColor: '#ffffff',
                    width: 1123,
                    height: 794,
                    x: 0,
                    y: 0,
                    scrollX: 0,
                    scrollY: 0,
                    windowWidth: 1400,
                    windowHeight: 1400
                });

                staging.remove();

                const canvas2 = document.createElement('canvas');
                canvas2.width = canvas2Raw.height;
                canvas2.height = canvas2Raw.width;
                const ctx2 = canvas2.getContext('2d');
                ctx2.fillStyle = '#ffffff';
                ctx2.fillRect(0, 0, canvas2.width, canvas2.height);
                ctx2.translate(canvas2.width, 0);
                ctx2.rotate(Math.PI / 2);
                ctx2.drawImage(canvas2Raw, 0, 0);

                // 3. Crear PDF A4 con ambas hojas Verticales (210mm x 297mm) idénticas al PDF oficial
                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF({
                    unit: 'mm',
                    format: 'a4',
                    orientation: 'portrait'
                });

                // Hoja 1: Acta Volante de Examen (A4 vertical: 210mm x 297mm)
                const imgData1 = canvas1.toDataURL('image/jpeg', 0.98);
                pdf.addImage(imgData1, 'JPEG', 0, 0, 210, 297, undefined, 'FAST');

                // Hoja 2: Acta de Desaprobación rotada a 90° en hoja A4 vertical (210mm x 297mm)
                pdf.addPage('a4', 'portrait');
                const imgData2 = canvas2.toDataURL('image/jpeg', 0.98);
                pdf.addImage(imgData2, 'JPEG', 0, 0, 210, 297, undefined, 'FAST');

                pdf.save(filename);

                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origText;
                }
            } else if (window.html2pdf) {
                const opt = {
                    margin:       0,
                    filename:     filename,
                    image:        { type: 'jpeg', quality: 0.98 },
                    html2canvas:  { scale: 2, useCORS: true, allowTaint: true, logging: false },
                    pagebreak:    { mode: ['css', 'legacy'] },
                    jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
                };
                await window.html2pdf().set(opt).from(document.getElementById('rosterPrintContainer')).save();
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origText;
                }
            } else {
                window.print();
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origText;
                }
            }
        } catch (err) {
            console.error('Error al generar PDF A4 del acta:', err);
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = origText;
            }
            window.print();
        }
    }

    function renderNotasTab() {
        const courseFilter = document.getElementById('filterCourseNota')?.value || '';
        const subjectFilter = document.getElementById('filterSubjectNota')?.value || '';
        const turnFilter = document.getElementById('filterTurnNota')?.value || (typeof getCurrentExamTurn === 'function' ? getCurrentExamTurn() : 'Febrero / Marzo');
        const approvalFilter = document.getElementById('filterApprovalNota')?.value || '';

        const records = DB.getFilteredEnrollments({
            course: courseFilter,
            subjectName: subjectFilter,
            examTurn: turnFilter,
            approvalStatus: approvalFilter
        });

        const countBadge = document.getElementById('notasFilterCount');
        if (countBadge) countBadge.textContent = `${records.length} alumno(s)`;

        const tbody = document.getElementById('notasTableBody');
        if (!tbody) return;
        tbody.innerHTML = '';

        if (records.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: #888; padding: 2rem;">No se encontraron alumnos con este estado de calificación.</td></tr>';
            return;
        }

        records.forEach(r => {
            const isPassed = r.grade !== null && r.grade >= 6;
            const isFailed = r.grade !== null && r.grade < 6;

            let statusBadge = '<span class="status-badge pending">SIN CALIFICAR</span>';
            if (isPassed) statusBadge = '<span class="status-badge passed"><span class="material-symbols-outlined">verified</span> APROBADO</span>';
            if (isFailed) statusBadge = '<span class="status-badge failed"><span class="material-symbols-outlined">cancel</span> DESAPROBADO</span>';

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><strong>${esc(r.studentName)}</strong><br><small style="color:#aaa;">DNI: ${esc(r.studentDni)}</small></td>
                <td><strong>${esc(r.owedYear)}</strong></td>
                <td>${esc(r.subjectName)}</td>
                <td>${esc(r.examTurn)}</td>
                <td>${statusBadge}</td>
                <td>
                    <select class="grade-select" data-enrollment="${esc(r.enrollmentId)}" data-subject="${esc(r.subjectId)}">
                        <option value="" ${r.grade === null ? 'selected' : ''}>-- Nota --</option>
                        ${[1,2,3,4,5,6,7,8,9,10].map(n => `
                            <option value="${n}" ${r.grade === n ? 'selected' : ''}>${n} ${n >= 6 ? '(Aprobado)' : '(Desaprobado)'}</option>
                        `).join('')}
                    </select>
                </td>
            `;

            tr.querySelector('.grade-select').addEventListener('change', (e) => {
                const val = e.target.value;
                DB.updateGrade(r.enrollmentId, r.subjectId, val);
                populateNotaFilters();
                renderNotasTab();
                renderStatsTab();
            });

            tbody.appendChild(tr);
        });
    }

    window.onServerConfigSynced = renderHabilitarTab;
    window.onServerDataSynced = refreshDirectivosDashboard;

    window.addEventListener("storage", (e) => {
        if (e.key === CONFIG_KEY || e.key === DB_KEY || e.key === MESAS_KEY) {
            refreshDirectivosDashboard();
        }
    });

    window.addEventListener("focus", () => {
        DB.syncWithServer();
        refreshDirectivosDashboard();
    });

    function initApp() {
        initDirectivosModule();
        initAllCustomSelects();
        DB.syncWithServer();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initApp);
    } else {
        initApp();
    }
})();