// Lógica del formulario de alumnos: carga de materias previas, validaciones y armado del permiso en PDF.
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
        initAllCustomSelects
    } = window.AppCore;

    // Acá vamos guardando las materias que el alumno agrega antes de generar el permiso
    const state = {
        addedSubjects: [],
        editingSubjectId: null
    };

    // Calcula las fechas mínima y máxima de nacimiento tomando la fecha real del dispositivo
    // y el curso actual: alumnos regulares (1ro a 7mo) tienen como año mínimo (año actual - 19),
    // o sea 2007 en 2026 (se calcula para que no quede desactualizado al cambiar el año),
    // mientras que los egresados pueden ser mucho mayores (adultos sin límite superior estricto).
    const EDAD_MAX_REGULAR = 19;
    function anioMinimoRegular() {
        return new Date().getFullYear() - EDAD_MAX_REGULAR;
    }

    function getBirthdateBounds(currentCourse) {
        const now = new Date();
        const currentYear = now.getFullYear();
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const dd = String(now.getDate()).padStart(2, '0');
        const todayStr = `${currentYear}-${mm}-${dd}`;

        let minAge = 12;
        let maxAge = 85;
        let minBirthYear = 1940;
        let minDateStr = '1940-01-01';

        if (currentCourse === 'Egreso') {
            minAge = 16;
            maxAge = 85; // Los egresados pueden ser mucho mayores
            minBirthYear = 1940;
            minDateStr = '1940-01-01';
        } else if (currentCourse && COURSE_LEVEL_MAP[currentCourse]) {
            const level = COURSE_LEVEL_MAP[currentCourse]; // 2do = 2 ... 7mo = 7
            minAge = 10 + level;
            // Para alumnos regulares (1ro a 7mo), el año mínimo reglamentario
            minBirthYear = anioMinimoRegular();
            minDateStr = `${minBirthYear}-01-01`;
        }

        const maxBirthYear = currentYear - minAge;

        // Ajustamos el día por si hoy es 29 de febrero y el año límite no es bisiesto
        const maxDateObj = new Date(maxBirthYear, now.getMonth(), now.getDate());
        const maxM = String(maxDateObj.getMonth() + 1).padStart(2, '0');
        const maxD = String(maxDateObj.getDate()).padStart(2, '0');

        return {
            todayStr,
            minAge,
            maxAge,
            minBirthYear,
            maxBirthYear,
            minDateStr,
            maxDateStr: `${maxDateObj.getFullYear()}-${maxM}-${maxD}`
        };
    }

    // Actualiza los límites del calendario de fecha de nacimiento según la fecha del dispositivo y el curso elegido
    function updateBirthdateConstraints() {
        const birthdateInput = document.getElementById('studentBirthdate');
        if (!birthdateInput) return;
        const currentCourse = document.getElementById('currentCourse')?.value || '';
        const bounds = getBirthdateBounds(currentCourse);
        birthdateInput.min = bounds.minDateStr;
        birthdateInput.max = bounds.maxDateStr;
    }

    // Revisa que la fecha de nacimiento no sea futura y esté dentro de una edad real para el curso y año adeudado
    function validateStudentBirthdate(birthStr, currentCourse, owedYear, calendarYear) {
        if (!birthStr || birthStr.length !== 10) {
            return { valid: false, message: "La fecha de nacimiento es obligatoria." };
        }

        const bounds = getBirthdateBounds(currentCourse);
        if (birthStr > bounds.todayStr) {
            return {
                valid: false,
                message: "La fecha de nacimiento no puede ser una fecha futura."
            };
        }

        const parts = birthStr.split('-');
        const birthYear = parseInt(parts[0], 10);

        // Si es egresado, puede ser mucho mayor (desde 1940 en adelante), solo requiere al menos 16 años
        if (currentCourse === 'Egreso') {
            if (isNaN(birthYear) || birthYear < 1940 || birthStr > bounds.maxDateStr) {
                return {
                    valid: false,
                    message: `Un alumno egresado debe tener al menos ${bounds.minAge} años.`
                };
            }
        } else {
            // Para alumnos regulares de 1ro a 7mo año, el año mínimo se calcula según el año actual
            const minRegular = anioMinimoRegular();
            if (isNaN(birthYear) || birthYear < minRegular) {
                return {
                    valid: false,
                    message: `Para alumnos regulares de 1ro a 7mo año, el año mínimo de nacimiento es ${minRegular}. Si eres egresado de promociones anteriores, selecciona 'Egresado' en Curso Actual.`
                };
            }
            if (birthStr > bounds.maxDateStr) {
                const courseLabel = currentCourse ? `un alumno de ${currentCourse} AÑO` : 'un estudiante regular';
                return {
                    valid: false,
                    message: `La fecha de nacimiento ingresada es muy reciente para ${courseLabel} (debe tener al menos ${bounds.minAge} años).`
                };
            }
        }

        if (owedYear && calendarYear) {
            const owedLevel = YEAR_LEVEL_MAP[owedYear] || 1;
            const calYearNum = parseInt(calendarYear, 10);
            const minAgeWhenCursed = 10 + owedLevel;
            if (!isNaN(calYearNum) && (calYearNum - birthYear) < minAgeWhenCursed) {
                return {
                    valid: false,
                    message: `Según tu fecha de nacimiento (${parts[2]}/${parts[1]}/${parts[0]}), en el año ${calendarYear} no tenías la edad acorde para cursar ${owedYear}.`
                };
            }
        }

        return { valid: true, message: "" };
    }

    // Formato automático para el teléfono y control de fecha de nacimiento
    function initInputRestrictions() {
        const dniInput = document.getElementById('studentDni');
        const phoneInput = document.getElementById('studentPhone');
        const birthdateInput = document.getElementById('studentBirthdate');

        if (dniInput) {
            dniInput.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').slice(0, 8);
            });
        }

        if (phoneInput) {
            phoneInput.addEventListener('input', function() {
                const raw = this.value.replace(/\D/g, '').slice(0, 11);
                if (raw.length === 0) {
                    this.value = '';
                } else if (raw.length <= 3) {
                    this.value = raw;
                } else if (raw.length <= 7) {
                    this.value = `${raw.slice(0, 3)} ${raw.slice(3)}`;
                } else if (raw.length <= 10) {
                    this.value = `${raw.slice(0, 3)} ${raw.slice(3, 6)}-${raw.slice(6)}`;
                } else {
                    this.value = `${raw.slice(0, 4)} ${raw.slice(4, 7)}-${raw.slice(7)}`;
                }
            });
        }

        if (birthdateInput) {
            updateBirthdateConstraints();
            birthdateInput.addEventListener('change', function() {
                if (!this.value || this.value.length !== 10) return;
                const currentCourse = document.getElementById('currentCourse')?.value || '';
                const owedYear = document.getElementById('owedYear')?.value || '';
                const calendarYear = document.getElementById('calendarYear')?.value || '';
                const check = validateStudentBirthdate(this.value, currentCourse, owedYear, calendarYear);
                if (!check.valid) {
                    highlightFieldError(this);
                    showAlert(check.message, "danger");
                }
            });
        }
    }

    function initFormEvents() {
        const currentCourseSelect = document.getElementById('currentCourse');
        const currentSpecialtySelect = document.getElementById('currentSpecialty');
        const specialtyContainer = document.getElementById('specialtyContainer');
        const owedYearSelect = document.getElementById('owedYear');
        const enrollmentForm = document.getElementById('enrollmentForm');

        if (!currentCourseSelect || !enrollmentForm) return;

        // Cuando cambia el curso actual, nos fijamos si corresponde pedir tecnicatura (de 4to en adelante)
        currentCourseSelect.addEventListener('change', () => {
            const selectedCourse = currentCourseSelect.value;
            const needsSpecialty = ['4to', '5to', '6to', '7mo', 'Egreso'].includes(selectedCourse);

            if (needsSpecialty) {
                specialtyContainer.classList.add('visible');
                currentSpecialtySelect.setAttribute('required', 'true');
            } else {
                specialtyContainer.classList.remove('visible');
                currentSpecialtySelect.removeAttribute('required');
                currentSpecialtySelect.value = '';
            }

            updateBirthdateConstraints();
            updateOwedYearOptions();
        });

        if (currentSpecialtySelect) {
            currentSpecialtySelect.addEventListener('change', () => {
                updateOwedSubjectsList();
            });
        }

        if (owedYearSelect) {
            owedYearSelect.addEventListener('change', () => {
                updateCalendarYearOptions();
                updateOwedSubjectsList();
            });
        }

        enrollmentForm.addEventListener('submit', (e) => {
            e.preventDefault();
            handleAddSubject();
        });

        const btnCancelEditSubject = document.getElementById('btnCancelEditSubject');
        if (btnCancelEditSubject) {
            btnCancelEditSubject.addEventListener('click', () => {
                cancelEditSubject();
            });
        }

        const addedSubjectsHeader = document.getElementById('addedSubjectsHeader');
        if (addedSubjectsHeader) {
            addedSubjectsHeader.addEventListener('click', (e) => {
                // Si el clic fue en un botón específico, dejar que su handler actúe
                if (e.target.closest('#btnOpenPdfFromCart')) return;
                prepareAndOpenPdf();
            });
        }

        const btnOpenPdfFromCart = document.getElementById('btnOpenPdfFromCart');
        if (btnOpenPdfFromCart) {
            btnOpenPdfFromCart.addEventListener('click', (e) => {
                e.stopPropagation();
                prepareAndOpenPdf();
            });
        }
    }

    // Solo dejamos elegir años anteriores al curso que está haciendo ahora (si es egresado puede elegir hasta 7mo)
    function updateOwedYearOptions() {
        const currentCourse = document.getElementById('currentCourse').value;
        const owedYearSelect = document.getElementById('owedYear');
        if (!owedYearSelect) return;

        const maxLevel = COURSE_LEVEL_MAP[currentCourse] || 8;
        let resetSelection = false;

        Array.from(owedYearSelect.options).forEach(opt => {
            if (!opt.value) return;
            const optLevel = YEAR_LEVEL_MAP[opt.value] || 1;
            if (optLevel >= maxLevel) {
                opt.disabled = true;
                opt.hidden = true;
                if (owedYearSelect.value === opt.value) {
                    resetSelection = true;
                }
            } else {
                opt.disabled = false;
                opt.hidden = false;
            }
        });

        if (resetSelection) {
            owedYearSelect.value = '';
        }
        if (owedYearSelect._refreshCustomOptions) {
            owedYearSelect._refreshCustomOptions();
        }
        updateCalendarYearOptions();
        updateOwedSubjectsList();
    }

    // Calcula el año calendario máximo posible según el curso actual y el curso de la materia adeudada
    function getMaxAllowedCalendarYear(currentCourse, owedYear) {
        const currentCalendarYear = new Date().getFullYear();
        if (!currentCourse || !owedYear) {
            return currentCalendarYear - 1;
        }
        const currentCourseLevel = COURSE_LEVEL_MAP[currentCourse] || 8;
        const owedYearLevel = YEAR_LEVEL_MAP[owedYear] || 1;
        const diff = Math.max(1, currentCourseLevel - owedYearLevel);
        return currentCalendarYear - diff;
    }

    // Filtra los años disponibles (hasta 2012) para que no pueda elegir un año mayor al que le corresponde
    function updateCalendarYearOptions() {
        const currentCourse = document.getElementById('currentCourse')?.value || '';
        const owedYear = document.getElementById('owedYear')?.value || '';
        const calendarYearSelect = document.getElementById('calendarYear');
        if (!calendarYearSelect) return;

        const maxYear = getMaxAllowedCalendarYear(currentCourse, owedYear);
        let resetNeeded = false;

        Array.from(calendarYearSelect.options).forEach(opt => {
            if (!opt.value) return;
            const y = parseInt(opt.value, 10);
            if (!isNaN(y) && y > maxYear) {
                opt.disabled = true;
                opt.hidden = true;
                if (calendarYearSelect.value === opt.value) {
                    resetNeeded = true;
                }
            } else {
                opt.disabled = false;
                opt.hidden = false;
            }
        });

        const currentSelectedYear = parseInt(calendarYearSelect.value, 10);
        if (resetNeeded || isNaN(currentSelectedYear) || currentSelectedYear > maxYear) {
            calendarYearSelect.value = String(maxYear);
        }

        if (calendarYearSelect._refreshCustomOptions) {
            calendarYearSelect._refreshCustomOptions();
        }
    }

    // Carga las materias en el desplegable según el año adeudado y la tecnicatura elegida
    function updateOwedSubjectsList() {
        const owedYearSelect = document.getElementById('owedYear');
        const currentSpecialtySelect = document.getElementById('currentSpecialty');
        const subjectSelect = document.getElementById('owedSubjectInput');

        if (!owedYearSelect || !subjectSelect) return;

        const owedYear = owedYearSelect.value;
        const currentSpecialty = currentSpecialtySelect ? currentSpecialtySelect.value : '';

        subjectSelect.innerHTML = '<option value="" disabled selected>-- Seleccionar Materia --</option>';
        if (!owedYear) {
            if (subjectSelect._refreshCustomOptions) subjectSelect._refreshCustomOptions();
            return;
        }

        let key = owedYear;
        if (['4to', '5to', '6to', '7mo'].includes(owedYear)) {
            if (!currentSpecialty) {
                if (subjectSelect._refreshCustomOptions) subjectSelect._refreshCustomOptions();
                return;
            } else {
                key = `${owedYear} ${currentSpecialty}`;
            }
        }

        const subjects = listado_materias[key] || [];

        subjects.forEach(subject => {
            const option = document.createElement('option');
            const isAlreadyAdded = state.addedSubjects.some(
                item => (state.editingSubjectId ? item.id !== state.editingSubjectId : true) &&
                        item.subjectName.toLowerCase() === subject.toLowerCase() &&
                        item.owedYear.includes(owedYear)
            );

            option.value = subject;
            if (isAlreadyAdded) {
                option.textContent = `${subject} (Ya inscripto)`;
                option.disabled = true;
            } else {
                option.textContent = subject;
            }
            subjectSelect.appendChild(option);
        });

        if (subjectSelect._refreshCustomOptions) {
            subjectSelect._refreshCustomOptions();
        }
    }

    function handleAddSubject() {
        clearFieldErrors();

        const preinscripcionStatus = DB.isPreinscripcionActive();
        if (!preinscripcionStatus.active) {
            showAlert("Las preinscripciones a mesas de examen se encuentran cerradas en este momento.", "danger");
            return;
        }

        const studentNameInput = document.getElementById('studentName');
        const studentDniInput = document.getElementById('studentDni');
        const studentBirthdateInput = document.getElementById('studentBirthdate');
        const studentEmailInput = document.getElementById('studentEmail');
        const studentPhoneInput = document.getElementById('studentPhone');
        const currentCourseSelect = document.getElementById('currentCourse');
        const currentSpecialtySelect = document.getElementById('currentSpecialty');
        const owedYearSelect = document.getElementById('owedYear');
        const calendarYearSelect = document.getElementById('calendarYear');
        const owedSubjectInput = document.getElementById('owedSubjectInput');
        const examTurnSelect = document.getElementById('examTurn');

        const studentName = studentNameInput.value.trim();
        const studentDni = studentDniInput.value.trim();
        const studentBirthdate = studentBirthdateInput ? studentBirthdateInput.value : '';
        const studentEmail = studentEmailInput.value.trim();
        const studentPhone = studentPhoneInput.value.trim();
        const currentCourse = currentCourseSelect.value;
        const currentSpecialty = currentSpecialtySelect.value;
        const owedYear = owedYearSelect.value;
        const calendarYear = calendarYearSelect.value;
        let owedSubject = owedSubjectInput.value;
        const examTurn = (examTurnSelect ? examTurnSelect.value : '') || getCurrentExamTurn();

        if (!studentName) {
            highlightFieldError(studentNameInput);
            showAlert("El Nombre y Apellido son obligatorios.", "danger");
            return;
        }

        if (!studentDni || studentDni.length !== 8) {
            highlightFieldError(studentDniInput);
            showAlert("El DNI es obligatorio y debe contener exactamente 8 números.", "danger");
            return;
        }

        const rawPhoneDigits = studentPhone.replace(/\D/g, '');
        if (!studentPhone || rawPhoneDigits.length < 6 || rawPhoneDigits.length > 11) {
            highlightFieldError(studentPhoneInput);
            showAlert("Por favor, ingresa un número de teléfono válido (hasta 11 números).", "danger");
            return;
        }

        const birthCheck = validateStudentBirthdate(studentBirthdate, currentCourse, owedYear, calendarYear);
        if (!birthCheck.valid) {
            highlightFieldError(studentBirthdateInput);
            showAlert(birthCheck.message, "danger");
            return;
        }

        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!studentEmail || !emailPattern.test(studentEmail)) {
            highlightFieldError(studentEmailInput);
            showAlert("Por favor, ingresa un correo electrónico (mail) válido.", "danger");
            return;
        }

        if (!currentCourse) {
            highlightFieldError(currentCourseSelect);
            showAlert("Debes seleccionar tu Curso Actual.", "danger");
            return;
        }

        const needsSpecialty = ['4to', '5to', '6to', '7mo', 'Egreso'].includes(currentCourse);
        if (needsSpecialty && !currentSpecialty) {
            highlightFieldError(currentSpecialtySelect);
            showAlert("Debes seleccionar tu Tecnicatura.", "danger");
            return;
        }

        if (!owedYear) {
            highlightFieldError(owedYearSelect);
            showAlert("Debes seleccionar el año en que cursaste la materia adeudada.", "danger");
            return;
        }

        const currentCourseLevel = COURSE_LEVEL_MAP[currentCourse] || 8;
        const owedYearLevel = YEAR_LEVEL_MAP[owedYear] || 1;

        if (owedYearLevel >= currentCourseLevel) {
            highlightFieldError(owedYearSelect);
            showAlert(`No puedes inscribirte a una materia de ${owedYear} si tu curso actual es ${currentCourse}. Solo puedes inscribirte a materias de años anteriores al que estás cursando.`, "danger");
            return;
        }

        const maxCalendarYear = getMaxAllowedCalendarYear(currentCourse, owedYear);
        const selectedCalendarYearNum = parseInt(calendarYear, 10);
        if (isNaN(selectedCalendarYearNum) || selectedCalendarYearNum > maxCalendarYear || selectedCalendarYearNum < 2012) {
            highlightFieldError(calendarYearSelect);
            showAlert(`El año en que se adeudó la materia (${calendarYear}) no corresponde. Si cursas ${currentCourse}, una materia de ${owedYear} debió cursarse como máximo en ${maxCalendarYear}.`, "danger");
            return;
        }

        if (!owedSubject) {
            highlightFieldError(owedSubjectInput);
            showAlert("Debes seleccionar la Materia Adeudada.", "danger");
            return;
        }

        let courseFormatted = currentCourse === 'Egreso' ? 'Egresado' : currentCourse;
        if (currentSpecialty && needsSpecialty) {
            courseFormatted += ` (${currentSpecialty})`;
        }

        let yearFormatted = owedYear;
        if (['4to', '5to', '6to', '7mo'].includes(owedYear) && currentSpecialty) {
            yearFormatted += ` ${currentSpecialty}`;
        }

        // Límite reglamentario: Alumnos regulares (1ro a 7mo) máximo 2 materias previas
        // Los alumnos con más de 2 materias previas deben repetir el año. Los egresados pueden elegir todas las que deseen.
        const isEgresado = currentCourse === 'Egreso';
        if (!state.editingSubjectId && !isEgresado && state.addedSubjects.length >= 2) {
            highlightFieldError(owedSubjectInput);
            showAlert(`Como alumno regular de ${currentCourse} AÑO, solo puedes inscribirte a un máximo de 2 materias previas reglamentarias (adeudar más de 2 materias implica la repetición del ciclo lectivo). Solo los egresados pueden solicitar más materias.`, "warning");
            return;
        }

        const isDuplicate = state.addedSubjects.some(item => 
            (state.editingSubjectId ? item.id !== state.editingSubjectId : true) &&
            item.subjectName.toLowerCase() === owedSubject.toLowerCase() && 
            item.owedYear.toLowerCase() === yearFormatted.toLowerCase()
        );

        if (isDuplicate) {
            highlightFieldError(owedSubjectInput);
            showAlert(`Ya te has inscripto previamente a la materia "${owedSubject}" (${yearFormatted}). No puedes inscribirte dos veces a la misma materia.`, "warning");
            return;
        }

        if (state.editingSubjectId) {
            const index = state.addedSubjects.findIndex(item => item.id === state.editingSubjectId);
            if (index !== -1) {
                state.addedSubjects[index] = {
                    ...state.addedSubjects[index],
                    subjectName: owedSubject,
                    course: courseFormatted,
                    owedYear: yearFormatted,
                    rawOwedYear: owedYear,
                    calendarYear: calendarYear,
                    examTurn: examTurn
                };
            }
            const updatedSubjectName = owedSubject;
            state.editingSubjectId = null;
            setEditModeUI(false);

            if (owedSubjectInput) {
                owedSubjectInput.value = '';
                owedSubjectInput.dispatchEvent(new Event('change'));
                if (owedSubjectInput._refreshCustomOptions) owedSubjectInput._refreshCustomOptions();
            }

            renderAddedSubjectsList();
            updateOwedSubjectsList();
            syncActiveStudentEnrollment();
            showAlert(`Materia "${updatedSubjectName}" modificada con éxito.`, "success");
            return;
        }

        const subjectItem = {
            id: Date.now(),
            subjectName: owedSubject,
            course: courseFormatted,
            owedYear: yearFormatted,
            rawOwedYear: owedYear,
            calendarYear: calendarYear,
            examTurn: examTurn
        };

        state.addedSubjects.push(subjectItem);

        renderAddedSubjectsList();
        updateOwedSubjectsList();
        syncActiveStudentEnrollment();
        openModal();
    }

    function getRawOwedYear(item) {
        if (item.rawOwedYear) return item.rawOwedYear;
        const y = item.owedYear || '';
        if (y.includes('1er') || y.includes('1ro')) return '1er AÑO';
        if (y.includes('2do')) return '2do AÑO';
        if (y.includes('3er') || y.includes('3ro')) return '3er AÑO';
        if (y.includes('4to')) return '4to';
        if (y.includes('5to')) return '5to';
        if (y.includes('6to')) return '6to';
        if (y.includes('7mo')) return '7mo';
        return y;
    }

    function setEditModeUI(isEditing) {
        const btnAddSubject = document.getElementById('btnAddSubject');
        const btnCancel = document.getElementById('btnCancelEditSubject');
        const btnText = document.getElementById('btnAddSubjectText');
        const btnIcon = document.getElementById('btnAddSubjectIcon');

        if (isEditing) {
            if (btnText) btnText.textContent = 'Guardar Cambios de la Materia';
            if (btnIcon) btnIcon.textContent = 'check_circle';
            if (btnAddSubject) {
                btnAddSubject.style.background = 'linear-gradient(180deg, #fff9c4 0%, #ffeb3b 100%)';
                btnAddSubject.style.borderColor = '#f57f17';
                btnAddSubject.style.color = '#4a0000';
            }
            if (btnCancel) btnCancel.style.display = 'inline-flex';
        } else {
            if (btnText) btnText.textContent = 'Agregar Materia a Solicitud';
            if (btnIcon) btnIcon.textContent = 'add_circle';
            if (btnAddSubject) {
                btnAddSubject.style.background = '';
                btnAddSubject.style.borderColor = '';
                btnAddSubject.style.color = '';
            }
            if (btnCancel) btnCancel.style.display = 'none';
        }
    }

    function startEditSubject(id) {
        const item = state.addedSubjects.find(s => s.id === id);
        if (!item) return;

        state.editingSubjectId = id;

        // Desplazar a la sección de materia adeudada
        const owedBlock = document.getElementById('owedSubjectBlock');
        if (owedBlock) {
            owedBlock.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // 1. Curso donde adeuda la materia
        const owedYearSelect = document.getElementById('owedYear');
        const rawYear = getRawOwedYear(item);
        if (owedYearSelect) {
            owedYearSelect.value = rawYear;
            owedYearSelect.dispatchEvent(new Event('change'));
            if (owedYearSelect._refreshCustomOptions) owedYearSelect._refreshCustomOptions();
        }

        // 2. Año calendario donde se adeudó
        const calendarYearSelect = document.getElementById('calendarYear');
        if (calendarYearSelect && item.calendarYear) {
            calendarYearSelect.value = item.calendarYear;
            calendarYearSelect.dispatchEvent(new Event('change'));
            if (calendarYearSelect._refreshCustomOptions) calendarYearSelect._refreshCustomOptions();
        }

        // 3. Materia
        updateOwedSubjectsList();
        const owedSubjectInput = document.getElementById('owedSubjectInput');
        if (owedSubjectInput) {
            owedSubjectInput.value = item.subjectName;
            owedSubjectInput.dispatchEvent(new Event('change'));
            if (owedSubjectInput._refreshCustomOptions) owedSubjectInput._refreshCustomOptions();
        }

        setEditModeUI(true);
        renderAddedSubjectsList();
    }

    function cancelEditSubject() {
        state.editingSubjectId = null;
        setEditModeUI(false);

        const owedSubjectInput = document.getElementById('owedSubjectInput');
        if (owedSubjectInput) {
            owedSubjectInput.value = '';
            owedSubjectInput.dispatchEvent(new Event('change'));
            if (owedSubjectInput._refreshCustomOptions) owedSubjectInput._refreshCustomOptions();
        }
        renderAddedSubjectsList();
        updateOwedSubjectsList();
    }

    function renderAddedSubjectsList() {
        const container = document.getElementById('addedSubjectsContainer');
        const list = document.getElementById('addedSubjectsList');
        const badgeCount = document.getElementById('subjectBadgeCount');

        if (!list || !container) return;

        list.innerHTML = '';

        if (state.addedSubjects.length === 0) {
            container.style.display = 'none';
            return;
        }

        container.style.display = 'block';
        if (badgeCount) {
            badgeCount.textContent = `${state.addedSubjects.length} materia(s) cargada(s)`;
        }

        state.addedSubjects.forEach((item, index) => {
            const isEditing = state.editingSubjectId === item.id;
            const itemEl = document.createElement('div');
            itemEl.className = 'added-subject-item' + (isEditing ? ' is-editing' : '');
            itemEl.innerHTML = `
                <div class="added-subject-info">
                    <div class="subject-name">${index + 1}. ${item.subjectName}</div>
                    <div class="subject-tags">
                        <span class="tag-pill"><span class="material-symbols-outlined">calendar_month</span> Adeudada en: ${item.calendarYear}</span>
                        <span class="tag-pill"><span class="material-symbols-outlined">school</span> ${item.owedYear}</span>
                        <span class="tag-pill"><span class="material-symbols-outlined">event</span> Turno: ${item.examTurn}</span>
                    </div>
                </div>
                <div class="added-subject-actions">
                    <button type="button" class="btn-edit" data-id="${item.id}" title="Editar esta materia">
                        <span class="material-symbols-outlined" style="font-size: 1rem;">edit</span> ${isEditing ? 'Editando...' : 'Editar'}
                    </button>
                    <button type="button" class="btn-remove" data-id="${item.id}" title="Quitar de la lista">
                        <span class="material-symbols-outlined" style="font-size: 1rem;">delete</span> Quitar
                    </button>
                </div>
            `;

            itemEl.querySelector('.btn-edit').addEventListener('click', () => {
                startEditSubject(item.id);
            });

            itemEl.querySelector('.btn-remove').addEventListener('click', () => {
                removeSubject(item.id);
            });

            list.appendChild(itemEl);
        });
    }

    function removeSubject(id) {
        if (state.editingSubjectId === id) {
            cancelEditSubject();
        }
        state.addedSubjects = state.addedSubjects.filter(item => item.id !== id);
        renderAddedSubjectsList();
        updateOwedSubjectsList();
        syncActiveStudentEnrollment();
    }

    // Guarda o actualiza inmediatamente la solicitud del alumno en la base de datos y localStorage
    function syncActiveStudentEnrollment() {
        const studentDbId = document.getElementById('studentDbId')?.value || '';
        const studentName = document.getElementById('studentName')?.value.trim() || '';
        const studentDni = document.getElementById('studentDni')?.value.trim() || '';
        const studentBirthdateVal = document.getElementById('studentBirthdate')?.value || '';
        const studentEmail = document.getElementById('studentEmail')?.value.trim() || '';
        const studentPhone = document.getElementById('studentPhone')?.value.trim() || '';
        const currentCourse = document.getElementById('currentCourse')?.value || '';
        const currentSpecialty = document.getElementById('currentSpecialty')?.value || '';

        if (!state.addedSubjects || state.addedSubjects.length === 0) {
            return;
        }

        if (!state.currentEnrollmentId) {
            state.currentEnrollmentId = 'INS-' + Date.now();
        }

        DB.save({
            id: state.currentEnrollmentId,
            alumnoId: studentDbId ? parseInt(studentDbId, 10) : null,
            studentName,
            studentDni,
            studentBirthdate: studentBirthdateVal,
            studentEmail,
            studentPhone,
            currentCourse: currentCourse === 'Egreso' ? 'Egresado' : currentCourse,
            currentSpecialty,
            subjects: state.addedSubjects
        });
    }

    function initModalEvents() {
        const btnYes = document.getElementById('btnModalYes');
        const btnNo = document.getElementById('btnModalNo');

        if (btnYes) {
            btnYes.addEventListener('click', () => {
                closeModal();
                const block1 = document.getElementById('studentDataBlock');
                const block2 = document.getElementById('academicStatusBlock');
                if (block1) block1.style.display = 'none';
                if (block2) block2.style.display = 'none';

                const subInput = document.getElementById('owedSubjectInput');
                if (subInput) {
                    subInput.value = '';
                    subInput.focus();
                }
                showAlert(`Materia agregada exitosamente. Puedes seleccionar otra asignatura adeudada. (${state.addedSubjects.length} agregada/s)`, "success");
            });
        }

        if (btnNo) {
            btnNo.addEventListener('click', () => {
                closeModal();
                finalizeEnrollment();
            });
        }
    }

    function openModal() {
        const modal = document.getElementById('modalMultiSubject');
        if (!modal) return;
        const currentCourse = document.getElementById('currentCourse')?.value || '';
        const isEgresado = currentCourse === 'Egreso';
        const titleEl = modal.querySelector('.modal-title');
        const bodyEl = modal.querySelector('.modal-body-text');
        const btnYes = document.getElementById('btnModalYes');
        const btnNo = document.getElementById('btnModalNo');

        if (!isEgresado && state.addedSubjects.length >= 2) {
            if (titleEl) titleEl.textContent = 'Límite Reglamentario Alcanzado (2 Materias)';
            if (bodyEl) {
                bodyEl.innerHTML = `Has completado las <strong>2 materias previas reglamentarias</strong> permitidas para alumnos regulares.<br><br>Por normativa institucional, los alumnos regulares (1ro a 7mo año) pueden rendir un máximo de 2 materias previas. Para continuar, finaliza y descarga tu permiso de examen.`;
            }
            if (btnYes) btnYes.style.display = 'none';
            if (btnNo) {
                btnNo.innerHTML = '<span class="material-symbols-outlined">description</span> Finalizar y Descargar PDF';
                btnNo.classList.remove('btn-secondary-auth');
                btnNo.classList.add('btn-ingresar');
            }
        } else {
            if (titleEl) titleEl.textContent = '¿Deseas inscribirte a otra materia?';
            if (bodyEl) {
                bodyEl.innerHTML = `La materia ha sido cargada correctamente a tu lista de preinscripción.<br><br>¿Quieres agregar otra materia adeudada a esta solicitud o deseas finalizar y descargar tu permiso?`;
            }
            if (btnYes) btnYes.style.display = 'inline-flex';
            if (btnNo) {
                btnNo.innerHTML = '<span class="material-symbols-outlined">description</span> No, finalizar y descargar PDF';
                btnNo.classList.remove('btn-ingresar');
                btnNo.classList.add('btn-secondary-auth');
            }
        }
        modal.classList.add('active');
    }

    function closeModal() {
        const modal = document.getElementById('modalMultiSubject');
        if (modal) modal.classList.remove('active');
    }

    function updatePdfDocument() {
        const studentNameInput = document.getElementById('studentName');
        const studentDniInput = document.getElementById('studentDni');
        const studentBirthdateInput = document.getElementById('studentBirthdate');
        const studentPhoneInput = document.getElementById('studentPhone');

        let studentName = studentNameInput ? studentNameInput.value.trim() : '';
        let studentDni = studentDniInput ? studentDniInput.value.trim() : '';
        let studentPhone = studentPhoneInput ? studentPhoneInput.value.trim() : '';
        let studentBirthdateVal = studentBirthdateInput ? studentBirthdateInput.value : '';

        // Si los campos de texto están vacíos, buscar en el último registro guardado en DB
        if (!studentName || !studentDni) {
            const allEnrollments = DB.getAll();
            if (allEnrollments && allEnrollments.length > 0) {
                const last = allEnrollments[allEnrollments.length - 1];
                if (!studentName && last.studentName) studentName = last.studentName;
                if (!studentDni && last.studentDni) studentDni = last.studentDni;
                if (!studentPhone && last.studentPhone) studentPhone = last.studentPhone;
                if (!studentBirthdateVal && last.studentBirthdate) studentBirthdateVal = last.studentBirthdate;
            }
        }

        const elName = document.getElementById('pdfStudentName');
        const elDni = document.getElementById('pdfStudentDni');
        const elPhone = document.getElementById('pdfStudentPhone');
        const elBirth = document.getElementById('pdfStudentBirthdate');

        if (elName) elName.textContent = studentName || "........................................................................................................................................................";
        if (elDni) elDni.textContent = studentDni || "...................................................";
        if (elPhone) elPhone.textContent = studentPhone || "...................................................";

        let formattedBirthdate = "................................................................................................................................";
        if (studentBirthdateVal) {
            const parts = studentBirthdateVal.split('-');
            if (parts.length === 3) {
                formattedBirthdate = `Concordia, ${parts[2]}/${parts[1]}/${parts[0]}`;
            }
        }
        if (elBirth) elBirth.textContent = formattedBirthdate;

        renderPdfTables();

        const escudoImg = document.getElementById('pdfEscudoImg');
        const logoImg = document.getElementById('pdfLogoImg');
        if (escudoImg && window.ESCUDO_BASE64) escudoImg.src = window.ESCUDO_BASE64;
        if (logoImg && window.LOGO_BASE64) logoImg.src = window.LOGO_BASE64;
    }

    function prepareAndOpenPdf() {
        if (!state.addedSubjects || state.addedSubjects.length === 0) {
            const allEnrollments = DB.getAll();
            if (allEnrollments && allEnrollments.length > 0) {
                const last = allEnrollments[allEnrollments.length - 1];
                if (last.subjects && last.subjects.length > 0) {
                    state.addedSubjects = [...last.subjects];
                    renderAddedSubjectsList();
                }
            }
        }

        if (!state.addedSubjects || state.addedSubjects.length === 0) {
            showAlert('No hay materias seleccionadas aún para generar el permiso.', 'danger');
            return;
        }

        updatePdfDocument();
        openPdfInNewTab();
    }

    function finalizeEnrollment() {
        syncActiveStudentEnrollment();

        updatePdfDocument();

        // Abrir la sección de descargar e imprimir PDF en una nueva pestaña
        openPdfInNewTab();

        state.currentEnrollmentId = null;

        // Al finalizar la preinscripción, la pantalla principal queda directamente en el inicio
        returnToHome();
    }

    function openPdfInNewTab() {
        const studentNameInput = document.getElementById('studentName');
        let studentName = studentNameInput ? studentNameInput.value.trim() : '';
        if (!studentName) {
            const allEnrollments = DB.getAll();
            if (allEnrollments && allEnrollments.length > 0) {
                studentName = allEnrollments[allEnrollments.length - 1].studentName || 'Alumno';
            } else {
                studentName = 'Alumno';
            }
        }
        const filename = `Permiso_Examen_${studentName.replace(/\s+/g, '_')}.pdf`;
        const pdfDocHtml = document.getElementById('pdfDocument').outerHTML;

        const cfg = DB.getConfig();
        const rawImporte = parseFloat(cfg.preinscripcion_importe || 3000);
        const formattedImporte = '$' + (isNaN(rawImporte) ? '3.000' : Math.round(rawImporte).toLocaleString('es-AR'));

        const newWindow = window.open('', '_blank');
        if (!newWindow) {
            alert("El navegador bloqueó la apertura de la nueva pestaña. Por favor, habilita las ventanas emergentes.");
            return;
        }

        const htmlContent = `<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permiso de Examen - ${studentName}</title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"><\/script>
    <style>
        body {
            background-color: #500000;
            padding: 24px 12px;
            margin: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
            font-family: 'Montserrat', sans-serif;
            box-sizing: border-box;
        }
        .pdf-preview-container {
            width: 100%;
            max-width: 820px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .pdf-actions-bar {
            width: 100%;
            max-width: 820px;
            margin-bottom: 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            align-items: center;
        }
        .pdf-document {
            background: white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            margin: 0 auto;
        }
        @page {
            size: A4 portrait;
            margin: 0;
        }
        @media print {
            body { background: white !important; padding: 0 !important; margin: 0 !important; }
            .no-print { display: none !important; }
            .pdf-document {
                box-shadow: none !important;
                width: 210mm !important;
                max-width: 210mm !important;
                height: 297mm !important;
                min-height: 297mm !important;
                max-height: 297mm !important;
                margin: 0 !important;
                padding: 8mm 10mm !important;
                box-sizing: border-box !important;
            }
        }
    </style>
</head>
<body>
    <div class="pdf-preview-container">
        <div class="alert-custom alert-success no-print" style="width: 100%; margin-bottom: 1.5rem; text-align: center; box-sizing: border-box; background: rgba(46, 125, 50, 0.2); border: 1px solid #4caf50; color: #e8f5e9; padding: 12px 18px; border-radius: 10px;">
            <span class="material-symbols-outlined" style="vertical-align: middle; margin-right: 0.3rem;">check_circle</span>
            <strong>¡Preinscripción cargada exitosamente!</strong><br>
            Descarga el permiso de examen en PDF a continuación o imprímelo para presentarlo en la escuela junto con el importe de inscripción de <strong style="color: #ffeb3b; text-decoration: underline;">${formattedImporte}</strong>.<br>
            <div style="margin-top: 0.5rem; padding: 0.4rem 0.6rem; background: rgba(0,0,0,0.25); border-radius: 6px; font-size: 0.88rem; color: #fff;">
                <span class="material-symbols-outlined" style="vertical-align: middle; font-size: 1rem; color: #ffeb3b;">info</span>
                <strong>Importante:</strong> La inscripción no está terminada hasta que se abone el valor en la institución.
            </div>
        </div>

        <div class="pdf-actions-bar no-print">
            <button id="btnDownloadPdfTab" class="btn-ingresar" style="width: 100%; max-width: 440px; padding: 14px 28px; background: #ffeb3b; color: #7a0000; border: 2px solid #f57c00; font-weight: 800; cursor: pointer; border-radius: 12px; font-size: 1rem; display: flex; align-items: center; justify-content: center; gap: 8px;">
                <span class="material-symbols-outlined">download</span> Descargar PDF (Permiso de Examen)
            </button>
            <div style="display: flex; gap: 0.8rem; width: 100%; max-width: 440px; justify-content: center;">
                <button id="btnPrintPdfTab" class="btn-secondary-auth" style="flex: 1; padding: 12px 14px; cursor: pointer; border-radius: 12px; display: flex; align-items: center; justify-content: center; gap: 6px; background: rgba(255,255,255,0.12); color: white; border: 1px solid rgba(255,255,255,0.3); font-weight: 700;">
                    <span class="material-symbols-outlined">print</span> Imprimir Permiso
                </button>
                <button onclick="closeAndReturn()" class="btn-secondary-auth" style="flex: 1; padding: 12px 14px; background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); cursor: pointer; border-radius: 12px; display: flex; align-items: center; justify-content: center; gap: 6px; font-weight: 700;">
                    <span class="material-symbols-outlined">close</span> Cerrar Pestaña
                </button>
            </div>
        </div>

        ${pdfDocHtml}
    </div>

    <script>
        function closeAndReturn() {
            try {
                if (window.opener && !window.opener.closed) {
                    if (typeof window.opener.returnToHome === 'function') {
                        window.opener.returnToHome();
                    }
                    window.opener.focus();
                }
            } catch (err) {
                console.error(err);
            }
            window.close();
        }
        window.closeAndReturn = closeAndReturn;

        const btnDownload = document.getElementById('btnDownloadPdfTab');
        const btnPrint = document.getElementById('btnPrintPdfTab');
        const element = document.getElementById('pdfDocument');
        const filename = ${JSON.stringify(filename)};

        if (btnDownload) {
            btnDownload.addEventListener('click', () => {
                if (window.html2pdf) {
                    btnDownload.disabled = true;
                    const orig = btnDownload.innerHTML;
                    btnDownload.innerHTML = '<span class="material-symbols-outlined">hourglass_top</span> Generando PDF...';
                    const opt = {
                        margin: [4, 4, 4, 4],
                        filename: filename,
                        image: { type: 'jpeg', quality: 0.98 },
                        html2canvas: { scale: 2, useCORS: true, allowTaint: true, logging: false, scrollX: 0, scrollY: 0 },
                        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
                    };
                    window.html2pdf().set(opt).from(element).save().then(() => {
                        btnDownload.disabled = false;
                        btnDownload.innerHTML = orig;
                    }).catch(err => {
                        btnDownload.disabled = false;
                        btnDownload.innerHTML = orig;
                        window.print();
                    });
                } else {
                    window.print();
                }
            });
        }

        if (btnPrint) {
            btnPrint.addEventListener('click', () => {
                window.print();
            });
        }
    <\/script>
</body>
</html>`;

        newWindow.document.open();
        newWindow.document.write(htmlContent);
        newWindow.document.close();
    }

    function renderPdfTables() {
        const tbody1 = document.getElementById('pdfTableBody1');
        const tbody2 = document.getElementById('pdfTableBody2');

        if (!tbody1 || !tbody2) return;

        tbody1.innerHTML = '';
        tbody2.innerHTML = '';

        const rowsCount = Math.max(state.addedSubjects.length, 4);

        for (let i = 0; i < rowsCount; i++) {
            const item = state.addedSubjects[i];

            const rowHtml = item ? `
                <tr>
                    <td class="subject-name-cell">${item.subjectName}</td>
                    <td>${item.course}</td>
                    <td>${item.calendarYear} (${item.owedYear})</td>
                    <td>${item.examTurn}</td>
                    <td></td>
                    <td></td>
                </tr>
            ` : `
                <tr>
                    <td class="subject-name-cell">&nbsp;</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            `;

            tbody1.innerHTML += rowHtml;
            tbody2.innerHTML += rowHtml;
        }
    }

    function initPdfEvents() {
        const btnDownload = document.getElementById('btnDownloadPdf');
        const btnPrint = document.getElementById('btnPrintPdf');
        const btnReturnHome = document.getElementById('btnReturnHome');

        if (btnDownload) {
            btnDownload.addEventListener('click', () => {
                const element = document.getElementById('pdfDocument');
                const studentNameInput = document.getElementById('studentName');
                const studentName = studentNameInput ? studentNameInput.value.trim() : 'Alumno';
                const filename = `Permiso_Examen_${studentName.replace(/\s+/g, '_')}.pdf`;

                const escudoImg = document.getElementById('pdfEscudoImg');
                const logoImg = document.getElementById('pdfLogoImg');
                if (escudoImg && window.ESCUDO_BASE64) escudoImg.src = window.ESCUDO_BASE64;
                if (logoImg && window.LOGO_BASE64) logoImg.src = window.LOGO_BASE64;

                const opt = {
                    margin:       [4, 4, 4, 4],
                    filename:     filename,
                    image:        { type: 'jpeg', quality: 0.98 },
                    html2canvas:  { 
                        scale: 2, 
                        useCORS: true,
                        allowTaint: true,
                        logging: false,
                        scrollX: 0,
                        scrollY: 0
                    },
                    jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
                };

                if (window.html2pdf) {
                    btnDownload.disabled = true;
                    const originalText = btnDownload.innerHTML;
                    btnDownload.innerHTML = '<span class="material-symbols-outlined">hourglass_top</span> Generando PDF...';

                    window.html2pdf().set(opt).from(element).save().then(() => {
                        btnDownload.disabled = false;
                        btnDownload.innerHTML = originalText;
                    }).catch(err => {
                        console.error('Error al generar PDF:', err);
                        btnDownload.disabled = false;
                        btnDownload.innerHTML = originalText;
                        window.print();
                    });
                } else {
                    window.print();
                }
            });
        }

        if (btnPrint) {
            btnPrint.addEventListener('click', () => {
                window.print();
            });
        }

        if (btnReturnHome) {
            btnReturnHome.addEventListener('click', () => {
                returnToHome();
            });
        }
    }

    function returnToHome() {
        cancelEditSubject();
        const pdfSection = document.getElementById('pdfPreviewSection');
        const formSection = document.getElementById('enrollmentFormSection');
        if (pdfSection) pdfSection.classList.remove('active');
        if (formSection) formSection.style.display = 'block';

        const block1 = document.getElementById('studentDataBlock');
        const block2 = document.getElementById('academicStatusBlock');
        if (block1) block1.style.display = 'block';
        if (block2) block2.style.display = 'block';

        const subInput = document.getElementById('owedSubjectInput');
        if (subInput) subInput.value = '';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    window.returnToHome = returnToHome;

    function showAlert(message, type = 'danger') {
        const alertBox = document.getElementById('alertBox');
        if (!alertBox) return;
        alertBox.className = `alert-custom alert-${type}`;
        alertBox.textContent = message;
        alertBox.style.display = 'block';

        setTimeout(() => {
            alertBox.style.display = 'none';
        }, 4500);
    }

    // Revisa si las inscripciones están abiertas o cerradas según lo que configuraron los directivos
    function updateStudentPreinscripcionStatus() {
        const status = DB.isPreinscripcionActive();
        const openNotice = document.getElementById('preinscripcionOpenNotice');
        const closedNotice = document.getElementById('preinscripcionClosedNotice');
        const enrollmentForm = document.getElementById('enrollmentForm');
        const addedSubjectsContainer = document.getElementById('addedSubjectsContainer');
        const examTurnSelect = document.getElementById('examTurn');
        const hiddenExamTurn = document.getElementById('hiddenExamTurn');

        const currentTurn = getCurrentExamTurn();
        if (examTurnSelect) {
            examTurnSelect.value = currentTurn;
            examTurnSelect.disabled = true;
            const wrapper = examTurnSelect.closest('.custom-select-wrapper');
            if (wrapper) {
                wrapper.classList.add('is-disabled');
                const lbl = wrapper.querySelector('.custom-select-label');
                if (lbl) {
                    lbl.textContent = currentTurn;
                }
            }
            if (examTurnSelect._refreshCustomOptions) {
                examTurnSelect._refreshCustomOptions();
            }
        }
        if (hiddenExamTurn) {
            hiddenExamTurn.value = currentTurn;
        }

        const formatD = (str) => {
            if (!str) return '';
            const parts = str.split('-');
            if (parts.length === 3) return `${parts[2]}/${parts[1]}/${parts[0]}`;
            return str;
        };

        const cfg = DB.getConfig();
        const alertImporteEl = document.getElementById('alertImporteInscripcion');
        if (alertImporteEl) {
            const importeNum = parseFloat(cfg.preinscripcion_importe || 3000);
            alertImporteEl.textContent = '$' + (isNaN(importeNum) ? '3.000' : Math.round(importeNum).toLocaleString('es-AR'));
        }

        if (status.active) {
            if (closedNotice) closedNotice.style.display = 'none';
            if (enrollmentForm) enrollmentForm.style.display = 'block';
            if (state.addedSubjects && state.addedSubjects.length > 0) {
                if (addedSubjectsContainer) addedSubjectsContainer.style.display = 'block';
            }
            if (openNotice) {
                openNotice.style.display = 'flex';
                const elStart = document.getElementById('openNoticeStartDate');
                const elEnd = document.getElementById('openNoticeEndDate');
                if (elStart) elStart.textContent = formatD(status.startDate) || 'Inicio de ciclo';
                if (elEnd) elEnd.textContent = formatD(status.endDate) || 'Fin de ciclo';
            }
        } else {
            if (openNotice) openNotice.style.display = 'none';
            if (enrollmentForm) enrollmentForm.style.display = 'none';
            if (addedSubjectsContainer) addedSubjectsContainer.style.display = 'none';
            if (closedNotice) {
                closedNotice.style.display = 'block';
                const reasonEl = document.getElementById('closedNoticeReason');
                const datesBox = document.getElementById('closedNoticeDatesBox');
                const datesText = document.getElementById('closedNoticeDatesText');

                if (reasonEl) reasonEl.textContent = status.message;
                if (datesText) {
                    if (status.startDate && status.endDate) {
                        datesText.textContent = `Período configurado: del ${formatD(status.startDate)} al ${formatD(status.endDate)}`;
                        if (datesBox) datesBox.style.display = 'inline-flex';
                    } else {
                        if (datesBox) datesBox.style.display = 'none';
                    }
                }
            }
        }
    }

    window.onServerConfigSynced = updateStudentPreinscripcionStatus;

    window.addEventListener("storage", (e) => {
        if (e.key === CONFIG_KEY || e.key === DB_KEY || e.key === MESAS_KEY) {
            updateStudentPreinscripcionStatus();
        }
    });

    function initApp() {
        initInputRestrictions();
        initFormEvents();
        initModalEvents();
        initPdfEvents();
        updateCalendarYearOptions();
        updateStudentPreinscripcionStatus();
        initAllCustomSelects();
        DB.syncWithServer();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initApp);
    } else {
        initApp();
    }
})();