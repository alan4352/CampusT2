(function() {
    // Escapa texto antes de insertarlo con innerHTML (los nombres, DNI y materias los escriben los alumnos)
    function esc(valor) {
        return String(valor ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    // Toda llamada a la API lleva esta cabecera: api.php rechaza los POST que no la traen (protección CSRF)
    function apiFetch(url, opciones = {}) {
        return fetch(url, { ...opciones, headers: { ...(opciones.headers || {}), 'X-Requested-With': 'preinscripciones' } });
    }

    // Materias de cada año y tecnicatura según el plan de estudios de la EET N° 2
    const listado_materias = {
      "1er AÑO": [
        "STE: Taller de Computación", "Matemática", "Lengua y Literatura", "Dibujo Técnico", "STE: Taller de Organización Empresarial", "Educación Musical", "Historia", "Formación Ética y Ciudadana", "Biología", "Educación Física", "Lengua Extranjera (Inglés)", "STE: Taller de Gastronomía", "Artes Visuales", "Educación Tecnológica", "Físico Química", "Geografía"
      ],
      "2do AÑO": [
        "STE: Taller de Computación", "Matemática", "Lengua y Literatura", "Dibujo Técnico", "STE: Taller de Organización Empresarial", "Educación Musical", "Historia", "Formación Ética y Ciudadana", "Biología", "Educación Física", "Lengua Extranjera (Inglés)", "STE: Taller de Gastronomía", "Artes Visuales", "Educación Tecnológica", "Físico Química", "Geografía"
      ],
      "3er AÑO": [
        "STE: Taller de Computación", "Matemática", "Lengua y Literatura", "Dibujo Técnico", "STE: Taller de Organización Empresarial", "Educación Musical", "Historia", "Formación Ética y Ciudadana", "Biología", "Física", "Educación Física", "Lengua Extranjera (Inglés)", "STE: Taller de Gastronomía", "Artes Visuales", "Educación Tecnológica", "Química", "Geografía"
      ],
      "4to TAE": [
        "Historia", "Formación Ética y Ciudadana", "Educación Física", "Dibujo Técnico", "Biología", "Geografía", "Física", "Elementos de Computación", "Lengua Extranjera (Inglés)", "Economía", "Práctica Contable", "Lengua y Literatura", "Matemática", "Taller de Computación", "Química", "T.I.C."
      ],
      "5to TAE": [
        "Proyecto de Prácticas", "Historia de la Ind. y Econ.", "Estudio del Prod. I", "Psicología General", "Geografía", "Computación I", "Lengua Extranjera (Inglés)", "Contabilidad I", "Sist. Adm. Contable I", "Lengua y Literatura", "Dirección Empresarial", "Inglés Técnico I", "Formación Ética y Ciudadana", "Matemática Técnica", "Principio Derecho Usual", "Educación Física"
      ],
      "6to TAE": [
        "Proyecto de Prácticas", "Historia de la Cult. e Inst. Arg.", "Estudio del Prod. II", "Derecho Com. y Adm.", "Psicología Industrial", "Geografía Econ. Arg", "Elem. de Economía", "Computación II", "Lengua Extranjera (Inglés)", "Contabilidad II", "Sist. Adm. Contable II", "Org. de la Prod. I", "Inglés Técnico II", "Matemática Técnica", "Estadística Aplicada", "Educación Física"
      ],
      "7mo TAE": [
        "Proyecto de Prácticas", "Dir. y Adm. Person.", "Derecho Social", "Investigación de Mercado", "Costos", "Teoría y Tec. Imposit.", "Computación III", "Interpretación de Balances", "Noc. de Adm. Financiera", "Nociones de Auditoría", "Org. de la Prod. II", "Análisis de Micro y Macroeconomía", "Inglés Técnico III", "Tec. Bancaria y Seguro", "Tec. de la Comercialización", "Educación Física"
      ],
      "4to TC": [
        "Historia", "Formación Ética y Ciudadana", "Educación Física", "Dibujo Técnico", "Biología", "Geografía", "Física", "Lengua Extranjera (Inglés)", "Lengua y Literatura", "Matemática", "Taller de Computación", "Química", "T.I.C."
      ],
      "5to TC": [
        "Historia económica y social universal", "Contabilidad I", "Sist.proc.de datos I", "Proyecto de Prácticas Profesionalizantes", "Electr. y técnicas digitales", "Lógica", "Lengua y Literatura", "Formación Ética y Ciudadana", "Organ. empresas", "Matemática I", "Inglés Técnico I", "Programación I", "Geografía", "Laboratorio I", "Educación Física"
      ],
      "6to TC": [
        "Historia económica y social argentina", "Contabilidad II", "Sist.proc.de datos II", "Técnicas digitales", "Proyecto de Prácticas Profesionalizantes", "Psicología", "Matemática II", "Sistemas administrativos", "Inglés Técnico II", "Programación II", "Geografía econ.gral", "Laboratorio II", "Educación Física"
      ],
      "7mo TC": [
        "Investigación operativa", "Int. a la econ. política", "Programación III", "Proyecto de Prácticas Profesionalizantes", "Contabilidad de costos", "Análisis de sistema", "Matemática III", "Inglés Técnico III", "Algorit. estadísticos por comput.", "Simulación", "Laboratorio III", "Educación Física"
      ],
      "4to TG": [
        "Historia", "Formación Ética y Ciudadana", "Educación Física", "Dibujo Técnico", "Biología", "Geografía", "Física", "Lengua Extranjera (Inglés)", "Economía", "Práctica Contable", "Lengua y Literatura", "Matemática", "Taller de Gastronomía", "Química", "T.I.C."
      ],
      "5to TG": [
        "Lengua Extranjera (Inglés)", "Lengua y Literatura", "Formación Ética y Ciudadana", "Geografía", "Historia", "Educación Física", "Música", "Matemática", "Biología", "Química Aplicada I", "Tecnología de Gestión", "Informática", "Dibujo Aplicado", "Seguridad e Higiene Industrial", "Economía", "Taller de Gastronomía", "Proyecto de Prácticas Profesionalizantes"
      ],
      "6to TG": [
        "Educación Física", "Lengua y Literatura", "Lengua Extranjera (Inglés)", "Formación Ética y Ciudadana", "Geografía", "Historia Aplicada", "Matemática", "Química Aplicada II", "Bromatología", "Informática", "Marketing", "Tecnología de Gestión", "Tecnología de los Materiales", "Tecnología de los Procesos Productivos", "Taller de Gastronomía", "Proyecto de Prácticas Profesionalizantes"
      ],
      "7mo TG": [
        "Educación Física", "Lengua y Literatura", "Lengua Extranjera (Inglés)", "Lengua Extranjera: Francés", "Ética y Deontología Profesional", "Matemática", "Protocolo y Ceremonial", "Informática", "Contabilidad", "Sociología de las Organizaciones", "Marco Jurídico de los Proc. Prod.", "Tecnología de los Procesos Productivos", "Tecnología de Gestión", "Taller de Gastronomía", "Proyecto de Prácticas Profesionalizantes"
      ]
    };

    // Calcula automáticamente qué turno de examen corresponde según el mes del año
    function getCurrentExamTurn() {
        const currentMonth = new Date().getMonth(); // 0 es Enero, 11 es Diciembre
        if (currentMonth >= 0 && currentMonth <= 3) {
            return 'Febrero / Marzo';
        } else if (currentMonth >= 4 && currentMonth <= 7) {
            return 'Julio / Agosto';
        } else {
            return 'Noviembre / Diciembre';
        }
    }

    const DB_KEY = 'eet2_preinscripciones_db';
    const MESAS_KEY = 'eet2_mesas_db';
    const DELETED_MESAS_KEY = 'eet2_deleted_mesas_db';
    const SEEDED_KEY = 'eet2_initial_seeded_v2';
    const CONFIG_KEY = 'eet2_config_db';

    // Alumnos de ejemplo que se muestran si la base todavía está vacía
    const MOCK_DATA = [
        {
            id: 'INS-1715000001',
            createdAt: '2026-02-10T14:20:00.000Z',
            dateFormatted: '10/2/2026 14:20',
            studentName: 'Gonzalo Ramírez',
            studentDni: '44123890',
            studentBirthdate: '2004-03-14',
            studentEmail: 'gonzalo.ramirez@gmail.com',
            studentPhone: '0345 421-9876',
            currentCourse: '6to (TC)',
            currentSpecialty: 'TC',
            paid: true,
            subjects: [
                { id: 101, subjectName: 'Programación II', course: '6to (TC)', owedYear: '6to TC', calendarYear: '2025', examTurn: 'Febrero / Marzo', paid: true, grade: 8 },
                { id: 102, subjectName: 'Matemática II', course: '6to (TC)', owedYear: '6to TC', calendarYear: '2025', examTurn: 'Febrero / Marzo', paid: true, grade: 4 }
            ]
        },
        {
            id: 'INS-1715000002',
            createdAt: '2026-02-11T09:15:00.000Z',
            dateFormatted: '11/2/2026 09:15',
            studentName: 'Martina Benítez',
            studentDni: '45890123',
            studentBirthdate: '2005-08-22',
            studentEmail: 'martina.benitez@gmail.com',
            studentPhone: '0345 415-3211',
            currentCourse: '5to (TAE)',
            currentSpecialty: 'TAE',
            paid: false,
            subjects: [
                { id: 103, subjectName: 'Contabilidad I', course: '5to (TAE)', owedYear: '5to TAE', calendarYear: '2025', examTurn: 'Febrero / Marzo', paid: false, grade: null }
            ]
        },
        {
            id: 'INS-1715000003',
            createdAt: '2026-02-12T11:45:00.000Z',
            dateFormatted: '12/2/2026 11:45',
            studentName: 'Lucas Fernández',
            studentDni: '43789012',
            studentBirthdate: '2003-11-05',
            studentEmail: 'lucas.fernandez@gmail.com',
            studentPhone: '0345 433-8890',
            currentCourse: 'Egresado',
            currentSpecialty: 'TG',
            paid: true,
            subjects: [
                { id: 104, subjectName: 'Química Aplicada II', course: 'Egresado', owedYear: '6to TG', calendarYear: '2024', examTurn: 'Julio / Agosto', paid: true, grade: 7 },
                { id: 105, subjectName: 'Taller de Gastronomía', course: 'Egresado', owedYear: '7mo TG', calendarYear: '2025', examTurn: 'Febrero / Marzo', paid: true, grade: 9 }
            ]
        },
        {
            id: 'INS-1715000004',
            createdAt: '2026-02-13T16:30:00.000Z',
            dateFormatted: '13/2/2026 16:30',
            studentName: 'Sofía Rossi',
            studentDni: '46210987',
            studentBirthdate: '2006-01-30',
            studentEmail: 'sofia.rossi@gmail.com',
            studentPhone: '0345 410-4455',
            currentCourse: '4to (TC)',
            currentSpecialty: 'TC',
            paid: true,
            subjects: [
                { id: 106, subjectName: 'Físico Química', course: '4to (TC)', owedYear: '3er AÑO', calendarYear: '2024', examTurn: 'Febrero / Marzo', paid: true, grade: 6 }
            ]
        },
        {
            id: 'INS-1715000005',
            createdAt: '2026-02-14T10:00:00.000Z',
            dateFormatted: '14/2/2026 10:00',
            studentName: 'Mateo Gómez',
            studentDni: '45112233',
            studentBirthdate: '2005-04-12',
            studentEmail: 'mateo.gomez@gmail.com',
            studentPhone: '0345 418-7766',
            currentCourse: '5to (TC)',
            currentSpecialty: 'TC',
            paid: false,
            subjects: [
                { id: 107, subjectName: 'Programación I', course: '5to (TC)', owedYear: '5to TC', calendarYear: '2025', examTurn: 'Noviembre / Diciembre', paid: false, grade: 3 },
                { id: 108, subjectName: 'Electr. y técnicas digitales', course: '5to (TC)', owedYear: '5to TC', calendarYear: '2025', examTurn: 'Febrero / Marzo', paid: false, grade: null }
            ]
        },
        {
            id: 'INS-1715000006',
            createdAt: '2026-02-15T15:10:00.000Z',
            dateFormatted: '15/2/2026 15:10',
            studentName: 'Camila Villalba',
            studentDni: '44998877',
            studentBirthdate: '2004-09-18',
            studentEmail: 'camila.v@gmail.com',
            studentPhone: '0345 422-3344',
            currentCourse: '6to (TAE)',
            currentSpecialty: 'TAE',
            paid: true,
            subjects: [
                { id: 109, subjectName: 'Sist. Adm. Contable II', course: '6to (TAE)', owedYear: '6to TAE', calendarYear: '2025', examTurn: 'Febrero / Marzo', paid: true, grade: 10 }
            ]
        },
        {
            id: 'INS-1715000007',
            createdAt: '2026-02-16T08:45:00.000Z',
            dateFormatted: '16/2/2026 08:45',
            studentName: 'Joaquín López',
            studentDni: '47123456',
            studentBirthdate: '2007-06-25',
            studentEmail: 'joaquin.lopez@gmail.com',
            studentPhone: '0345 401-9988',
            currentCourse: '3ro',
            currentSpecialty: '',
            paid: false,
            subjects: [
                { id: 110, subjectName: 'Matemática', course: '3ro', owedYear: '2do AÑO', calendarYear: '2024', examTurn: 'Febrero / Marzo', paid: false, grade: 2 }
            ]
        },
        {
            id: 'INS-1715000008',
            createdAt: '2026-02-17T17:50:00.000Z',
            dateFormatted: '17/2/2026 17:50',
            studentName: 'Valentina Maidana',
            studentDni: '43556677',
            studentBirthdate: '2003-02-14',
            studentEmail: 'valentina.m@gmail.com',
            studentPhone: '0345 419-5566',
            currentCourse: 'Egresado',
            currentSpecialty: 'TAE',
            paid: true,
            subjects: [
                { id: 111, subjectName: 'Costos', course: 'Egresado', owedYear: '7mo TAE', calendarYear: '2024', examTurn: 'Noviembre / Diciembre', paid: true, grade: 8 }
            ]
        },
        {
            id: 'INS-1715000009',
            createdAt: '2026-02-18T10:15:00.000Z',
            dateFormatted: '18/2/2026 10:15',
            studentName: 'Agustín Pereyra',
            studentDni: '46889900',
            studentBirthdate: '2006-07-11',
            studentEmail: 'agustin.pereyra@gmail.com',
            studentPhone: '0345 431-2244',
            currentCourse: '4to (TG)',
            currentSpecialty: 'TG',
            paid: true,
            subjects: [
                { id: 112, subjectName: 'Biología', course: '4to (TG)', owedYear: '3er AÑO', calendarYear: '2024', examTurn: 'Julio / Agosto', paid: true, grade: 6 }
            ]
        },
        {
            id: 'INS-1715000010',
            createdAt: '2026-02-19T13:40:00.000Z',
            dateFormatted: '19/2/2026 13:40',
            studentName: 'Delfina Romero',
            studentDni: '45334455',
            studentBirthdate: '2005-12-03',
            studentEmail: 'delfina.romero@gmail.com',
            studentPhone: '0345 416-7788',
            currentCourse: '5to (TAE)',
            currentSpecialty: 'TAE',
            paid: true,
            subjects: [
                { id: 113, subjectName: 'Contabilidad I', course: '5to (TAE)', owedYear: '5to TAE', calendarYear: '2025', examTurn: 'Febrero / Marzo', paid: true, grade: 7 }
            ]
        },
        {
            id: 'INS-1715000011',
            createdAt: '2026-02-20T11:20:00.000Z',
            dateFormatted: '20/2/2026 11:20',
            studentName: 'Facundo Castro',
            studentDni: '44667788',
            studentBirthdate: '2004-10-29',
            studentEmail: 'facundo.castro@gmail.com',
            studentPhone: '0345 428-1133',
            currentCourse: '6to (TC)',
            currentSpecialty: 'TC',
            paid: false,
            subjects: [
                { id: 114, subjectName: 'Programación II', course: '6to (TC)', owedYear: '6to TC', calendarYear: '2025', examTurn: 'Febrero / Marzo', paid: false, grade: null }
            ]
        },
        {
            id: 'INS-1715000012',
            createdAt: '2026-02-21T16:05:00.000Z',
            dateFormatted: '21/2/2026 16:05',
            studentName: 'Lucía Morales',
            studentDni: '47990011',
            studentBirthdate: '2007-03-08',
            studentEmail: 'lucia.morales@gmail.com',
            studentPhone: '0345 405-6677',
            currentCourse: '2do',
            currentSpecialty: '',
            paid: true,
            subjects: [
                { id: 115, subjectName: 'Educación Tecnológica', course: '2do', owedYear: '1er AÑO', calendarYear: '2024', examTurn: 'Noviembre / Diciembre', paid: true, grade: 9 }
            ]
        }
    ];

    // Mesas de examen simuladas creadas por el equipo directivo
    const MESAS_MOCK_DATA = [
        {
            id: 'MESA-001',
            course: '6to (TC)',
            courses: ['6to'],
            specialty: 'TC',
            subjectName: 'Programación II',
            subjectsArray: ['Programación II'],
            examTurn: 'Febrero / Marzo',
            examDate: '2026-03-02',
            examTime: '08:00',
            classroom: 'Laboratorio',
            tribunal: 'Martínez Sergio, Giménez Roberto, Sosa Valeria',
            presDocente: 'Martínez Sergio',
            v1Docente: 'Giménez Roberto',
            v2Docente: 'Sosa Valeria',
            createdAt: '2026-02-20'
        },
        {
            id: 'MESA-002',
            course: '5to (TAE)',
            courses: ['5to'],
            specialty: 'TAE',
            subjectName: 'Contabilidad I',
            subjectsArray: ['Contabilidad I'],
            examTurn: 'Febrero / Marzo',
            examDate: '2026-03-03',
            examTime: '09:30',
            classroom: '12',
            tribunal: 'Delgado Marta, Albornoz Carlos, Ríos Patricia',
            presDocente: 'Delgado Marta',
            v1Docente: 'Albornoz Carlos',
            v2Docente: 'Ríos Patricia',
            createdAt: '2026-02-20'
        },
        {
            id: 'MESA-003',
            course: '6to (TG)',
            courses: ['6to'],
            specialty: 'TG',
            subjectName: 'Química Aplicada II',
            subjectsArray: ['Química Aplicada II'],
            examTurn: 'Julio / Agosto',
            examDate: '2026-07-15',
            examTime: '14:00',
            classroom: 'Taller G. 1',
            tribunal: 'Rossi Andrea, Blanco Pablo, Méndez Laura',
            presDocente: 'Rossi Andrea',
            v1Docente: 'Blanco Pablo',
            v2Docente: 'Méndez Laura',
            createdAt: '2026-02-22'
        }
    ];

    const DB = {
        getAll() {
            try {
                const data = localStorage.getItem(DB_KEY);
                let enrollments = [];
                if (data === null && !localStorage.getItem(SEEDED_KEY)) {
                    this.seedMockData();
                    enrollments = MOCK_DATA;
                } else {
                    enrollments = data ? JSON.parse(data) : [];
                }

                // Sanitización preventiva: normalizar nombres de alumnos y materias ("Lengua Extranjera (Inglés)")
                enrollments.forEach(r => {
                    if (!r.studentName || /^\d+$/.test(String(r.studentName).trim()) || String(r.studentName).startsWith('INS-')) {
                        r.studentName = "Juan Pérez";
                    }
                    if (!r.studentDni || String(r.studentDni).length < 7) {
                        r.studentDni = "42345678";
                    }
                    if (r.subjects && Array.isArray(r.subjects)) {
                        r.subjects.forEach(s => {
                            if (s.subjectName && /^lengua extranjera[:\s]+ingl[eé]s$/i.test(s.subjectName.trim())) {
                                s.subjectName = "Lengua Extranjera (Inglés)";
                            }
                        });
                    }
                });

                return enrollments;
            } catch (e) {
                console.error("Error al leer preinscripciones:", e);
                return MOCK_DATA;
            }
        },

        seedMockData() {
            localStorage.setItem(SEEDED_KEY, '1');
            localStorage.setItem(DB_KEY, JSON.stringify(MOCK_DATA));
            if (localStorage.getItem(MESAS_KEY) === null) {
                localStorage.setItem(MESAS_KEY, JSON.stringify(MESAS_MOCK_DATA));
            }
            return MOCK_DATA;
        },

        save(record) {
            const enrollments = this.getAll();
            const recordId = record.id || ('INS-' + Date.now());
            const existingIdx = enrollments.findIndex(r => r.id === recordId);
            const newRecord = {
                id: recordId,
                createdAt: existingIdx !== -1 ? enrollments[existingIdx].createdAt : new Date().toISOString(),
                dateFormatted: existingIdx !== -1 ? enrollments[existingIdx].dateFormatted : (new Date().toLocaleDateString('es-AR') + ' ' + new Date().toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' })),
                paid: existingIdx !== -1 ? !!enrollments[existingIdx].paid : false,
                ...record,
                id: recordId
            };

            if (newRecord.subjects && Array.isArray(newRecord.subjects)) {
                newRecord.subjects = newRecord.subjects.map(s => ({
                    ...s,
                    paid: s.paid !== undefined ? !!s.paid : false,
                    grade: s.grade !== undefined ? s.grade : null
                }));
            }

            if (existingIdx !== -1) {
                enrollments[existingIdx] = newRecord;
            } else {
                enrollments.push(newRecord);
            }
            localStorage.setItem(DB_KEY, JSON.stringify(enrollments));

            // Guardamos la inscripción en la base de datos MySQL
            try {
                apiFetch('api.php?action=save_enrollment', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(newRecord)
                }).catch(e => console.warn("Modo local (sin MySQL):", e));
            } catch (e) {}

            return newRecord;
        },

        updatePaymentStatus(enrollmentId, subjectId, isPaid) {
            const enrollments = this.getAll();
            const record = enrollments.find(r => r.id === enrollmentId);

            if (record) {
                let allSubjectsPaid = true;
                record.subjects.forEach(sub => {
                    if (sub.id === subjectId || String(sub.id) === String(subjectId)) {
                        sub.paid = isPaid;
                    }
                    if (!sub.paid) allSubjectsPaid = false;
                });

                record.paid = allSubjectsPaid;
                localStorage.setItem(DB_KEY, JSON.stringify(enrollments));

                // Sincronizar con base de datos MySQL en segundo plano
                try {
                    apiFetch('api.php?action=update_payment', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ enrollmentId, subjectId, isPaid })
                    }).catch(e => console.warn("Modo local (sin MySQL):", e));
                } catch (e) {}

                return true;
            }
            return false;
        },

        updateGrade(enrollmentId, subjectId, gradeValue) {
            const enrollments = this.getAll();
            const record = enrollments.find(r => r.id === enrollmentId);

            if (record) {
                const gradeNum = gradeValue === '' || gradeValue === null ? null : parseInt(gradeValue, 10);
                record.subjects.forEach(sub => {
                    if (sub.id === subjectId || String(sub.id) === String(subjectId)) {
                        sub.grade = (gradeNum >= 1 && gradeNum <= 10) ? gradeNum : null;
                    }
                });

                localStorage.setItem(DB_KEY, JSON.stringify(enrollments));

                // Sincronizar con base de datos MySQL en segundo plano
                try {
                    apiFetch('api.php?action=update_grade', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ enrollmentId, subjectId, grade: gradeNum })
                    }).catch(e => console.warn("Modo local (sin MySQL):", e));
                } catch (e) {}

                return true;
            }
            return false;
        },

        async clearTurnEnrollments(turn) {
            try {
                let enrollments = this.getAll();
                if (turn) {
                    const turnNorm = turn.trim().toLowerCase();
                    enrollments = enrollments.map(rec => {
                        const remainingSubs = (rec.subjects || []).filter(s => (s.examTurn || '').trim().toLowerCase() !== turnNorm);
                        return { ...rec, subjects: remainingSubs };
                    }).filter(rec => rec.subjects.length > 0);
                } else {
                    enrollments = [];
                }
                localStorage.setItem(DB_KEY, JSON.stringify(enrollments));

                try {
                    await apiFetch('api.php?action=clear_enrollments', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ turn: turn || '' })
                    });
                } catch (e) {
                    console.warn("Modo local al vaciar tabla:", e);
                }
                return true;
            } catch (err) {
                console.error("Error al vaciar tabla de inscripciones:", err);
                return false;
            }
        },

        sanitizeMesaCourses(mesa) {
            if (!mesa || !mesa.course || !mesa.subjectName) return mesa;
            const normStr = (str) => String(str || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[.:,]/g, ' ').replace(/\s+/g, ' ').trim();
            let spec = (mesa.specialty || '').trim().toUpperCase();
            if (!spec) {
                if (mesa.course.includes('TAE')) spec = 'TAE';
                else if (mesa.course.includes('TC')) spec = 'TC';
                else if (mesa.course.includes('TG')) spec = 'TG';
            }
            const courseTokens = [];
            const rawMatches = String(mesa.course).match(/(1er AÑO|2do AÑO|3er AÑO|1ro|2do|3ro|4to|5to|6to|7mo)/g) || [];
            rawMatches.forEach(m => {
                let code = m;
                if (m.startsWith('1')) code = '1ro';
                else if (m.startsWith('2')) code = '2do';
                else if (m.startsWith('3')) code = '3ro';
                if (!courseTokens.includes(code)) courseTokens.push(code);
            });
            if (courseTokens.length <= 1) return mesa;

            const subjectsList = (mesa.subjectsArray && Array.isArray(mesa.subjectsArray) && mesa.subjectsArray.length > 0)
                ? mesa.subjectsArray
                : String(mesa.subjectName).split(',').map(s => s.trim()).filter(Boolean);

            const validCourses = courseTokens.filter(c => {
                let key = c;
                if (c === '1ro') key = '1er AÑO';
                else if (c === '2do') key = '2do AÑO';
                else if (c === '3ro') key = '3er AÑO';
                else if (spec) key = `${c} ${spec}`;
                const plan = listado_materias[key] || [];
                return subjectsList.some(sub => plan.some(p => normStr(p) === normStr(sub)));
            });

            if (validCourses.length > 0 && validCourses.length !== courseTokens.length) {
                const labels = validCourses.map(c => {
                    if (c === '1ro') return '1er AÑO';
                    if (c === '2do') return '2do AÑO';
                    if (c === '3ro') return '3er AÑO';
                    return c;
                });
                const isSup = validCourses.some(c => ['4to', '5to', '6to', '7mo'].includes(c));
                const newCourseStr = labels.join(', ') + (spec && isSup ? ` (${spec})` : '');
                const updated = {
                    ...mesa,
                    course: newCourseStr,
                    courses: validCourses,
                    courseRaw: labels.join(', ')
                };
                try {
                    apiFetch('api.php?action=save_mesa', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(updated)
                    }).catch(() => {});
                } catch (e) {}
                return updated;
            }
            return mesa;
        },

        getDeletedMesaIds() {
            try {
                const raw = localStorage.getItem(DELETED_MESAS_KEY);
                const arr = raw ? JSON.parse(raw) : [];
                return new Set(Array.isArray(arr) ? arr.map(String) : []);
            } catch (e) {
                return new Set();
            }
        },

        markMesaDeleted(mesaId) {
            try {
                const set = this.getDeletedMesaIds();
                set.add(String(mesaId));
                localStorage.setItem(DELETED_MESAS_KEY, JSON.stringify(Array.from(set)));
            } catch (e) {}
        },

        getMesas() {
            try {
                const data = localStorage.getItem(MESAS_KEY);
                if (data === null) {
                    if (!localStorage.getItem(SEEDED_KEY)) {
                        localStorage.setItem(SEEDED_KEY, '1');
                        localStorage.setItem(MESAS_KEY, JSON.stringify(MESAS_MOCK_DATA));
                        return MESAS_MOCK_DATA;
                    }
                    localStorage.setItem(MESAS_KEY, JSON.stringify([]));
                    return [];
                }
                const parsed = JSON.parse(data);
                if (!Array.isArray(parsed)) return [];
                const deletedIds = this.getDeletedMesaIds();
                let changed = false;
                const filtered = parsed.filter(m => {
                    if (!m || deletedIds.has(String(m.id))) {
                        changed = true;
                        return false;
                    }
                    return true;
                });
                const sanitized = filtered.map(m => {
                    const clean = this.sanitizeMesaCourses(m);
                    if (clean !== m) changed = true;
                    return clean;
                });
                if (changed) {
                    localStorage.setItem(MESAS_KEY, JSON.stringify(sanitized));
                }
                return sanitized;
            } catch (e) {
                return [];
            }
        },

        createMesa(mesaData) {
            const mesas = this.getMesas();
            const newMesa = {
                id: 'MESA-' + String(Date.now()).slice(-4),
                createdAt: new Date().toISOString().split('T')[0],
                ...mesaData
            };
            mesas.push(newMesa);
            localStorage.setItem(MESAS_KEY, JSON.stringify(mesas));

            // Sincronizar con base de datos MySQL en segundo plano
            try {
                apiFetch('api.php?action=create_mesa', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(newMesa)
                }).catch(e => console.warn("Modo local (sin MySQL):", e));
            } catch (e) {}

            return newMesa;
        },

        deleteMesa(mesaId) {
            const targetId = String(mesaId);
            this.markMesaDeleted(targetId);
            const data = localStorage.getItem(MESAS_KEY);
            let mesas = data ? JSON.parse(data) : [];
            mesas = (Array.isArray(mesas) ? mesas : []).filter(m => String(m.id) !== targetId);
            localStorage.setItem(SEEDED_KEY, '1');
            localStorage.setItem(MESAS_KEY, JSON.stringify(mesas));

            // Sincronizar eliminación en MySQL
            try {
                apiFetch('api.php?action=delete_mesa&id=' + encodeURIComponent(targetId), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: targetId })
                }).catch(e => console.warn("Modo local (sin MySQL):", e));
            } catch (e) {}
        },

        updateMesa(mesaId, updatedData) {
            let mesas = this.getMesas();
            const index = mesas.findIndex(m => m.id === mesaId);
            if (index !== -1) {
                mesas[index] = {
                    ...mesas[index],
                    ...updatedData,
                    updatedAt: new Date().toISOString()
                };
                localStorage.setItem(MESAS_KEY, JSON.stringify(mesas));

                // Sincronizar en MySQL
                try {
                    apiFetch('api.php?action=save_mesa', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(mesas[index])
                    }).catch(e => console.warn("Modo local (sin MySQL):", e));
                } catch (e) {}

                return mesas[index];
            }
            return null;
        },

        // Sincronización bidireccional automática con MySQL / MariaDB (eet2_mesas)
        async syncWithServer() {
            try {
                const resStatus = await apiFetch('api.php?action=test_connection');
                if (!resStatus.ok) throw new Error("API no disponible");
                const statusData = await resStatus.json();

                if (statusData && statusData.success && statusData.db_connected) {
                    // Traemos las inscripciones guardadas en MySQL y las combinamos con las locales
                    try {
                        const resEnr = await apiFetch('api.php?action=get_enrollments');
                        const enrData = await resEnr.json();
                        if (enrData && enrData.success && Array.isArray(enrData.data) && enrData.data.length > 0) {
                            const localRaw = localStorage.getItem(DB_KEY);
                            const localList = localRaw ? JSON.parse(localRaw) : [];
                            const mergedMap = new Map();
                            localList.forEach(item => {
                                if (item && item.id) mergedMap.set(item.id, item);
                            });
                            enrData.data.forEach(serverItem => {
                                if (serverItem && serverItem.id) {
                                    mergedMap.set(serverItem.id, serverItem);
                                }
                            });
                            localStorage.setItem(DB_KEY, JSON.stringify(Array.from(mergedMap.values())));
                        }
                    } catch (e) {}

                    // Traemos las mesas guardadas en MySQL y las combinamos con las locales respetando las eliminadas
                    try {
                        const resMesas = await apiFetch('api.php?action=get_mesas');
                        const mesasData = await resMesas.json();
                        if (mesasData && mesasData.success && Array.isArray(mesasData.data)) {
                            const deletedIds = this.getDeletedMesaIds();
                            const localRaw = localStorage.getItem(MESAS_KEY);
                            const localList = localRaw ? JSON.parse(localRaw) : [];
                            const mergedMesas = new Map();
                            (Array.isArray(localList) ? localList : []).forEach(item => {
                                if (item && item.id && !deletedIds.has(String(item.id))) {
                                    mergedMesas.set(String(item.id), item);
                                }
                            });
                            mesasData.data.forEach(serverItem => {
                                if (!serverItem || !serverItem.id) return;
                                const sid = String(serverItem.id);
                                if (deletedIds.has(sid)) {
                                    // Si fue eliminada localmente, asegurar que también se borre en MySQL
                                    apiFetch('api.php?action=delete_mesa&id=' + encodeURIComponent(sid), { method: 'POST' }).catch(() => {});
                                    return;
                                }
                                const existing = mergedMesas.get(sid) || {};
                                mergedMesas.set(sid, { ...existing, ...serverItem });
                            });
                            localStorage.setItem(SEEDED_KEY, '1');
                            localStorage.setItem(MESAS_KEY, JSON.stringify(Array.from(mergedMesas.values())));
                        }
                    } catch (e) {}

                    // Sincronizar configuración de preinscripción desde MySQL
                    try {
                        const resCfg = await apiFetch('api.php?action=get_config');
                        const cfgData = await resCfg.json();
                        if (cfgData && cfgData.success && cfgData.data) {
                            localStorage.setItem(CONFIG_KEY, JSON.stringify(cfgData.data));
                            if (typeof window.onServerConfigSynced === "function") {
                                window.onServerConfigSynced();
                            }
                        }
                    } catch (e) {}

                    if (typeof window.onServerDataSynced === "function") {
                        window.onServerDataSynced();
                    }
                }
            } catch (err) {
                // Si el servidor no responde, continúa operando con copia local
            }
        },

        getConfig() {
            try {
                const curYear = new Date().getFullYear();
                const defaultCfg = {
                    preinscripcion_habilitada: '1',
                    preinscripcion_fecha_inicio: `${curYear}-01-01`,
                    preinscripcion_fecha_fin: `${curYear}-12-31`,
                    preinscripcion_importe: '3000'
                };
                const raw = localStorage.getItem(CONFIG_KEY);
                if (!raw) return defaultCfg;
                return { ...defaultCfg, ...JSON.parse(raw) };
            } catch (e) {
                return {
                    preinscripcion_habilitada: '1',
                    preinscripcion_fecha_inicio: '2026-01-01',
                    preinscripcion_fecha_fin: '2026-12-31',
                    preinscripcion_importe: '3000'
                };
            }
        },

        async saveConfig(cfg) {
            try {
                const current = this.getConfig();
                const updated = { ...current, ...cfg };
                localStorage.setItem(CONFIG_KEY, JSON.stringify(updated));

                // Sincronizar en MySQL
                try {
                    await apiFetch('api.php?action=save_config', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(updated)
                    });
                } catch (err) {
                    console.warn("Modo local para configuración:", err);
                }
                return updated;
            } catch (e) {
                console.error("Error al guardar configuración:", e);
                return null;
            }
        },

        isPreinscripcionActive() {
            const cfg = this.getConfig();
            const d = new Date();
            const year = d.getFullYear();
            const month = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            const today = `${year}-${month}-${day}`;

            const isEnabled = String(cfg.preinscripcion_habilitada) === '1' || cfg.preinscripcion_habilitada === true;
            const start = cfg.preinscripcion_fecha_inicio || '';
            const end = cfg.preinscripcion_fecha_fin || '';

            if (!isEnabled) {
                return {
                    active: false,
                    reason: 'disabled',
                    message: 'Las preinscripciones se encuentran cerradas temporalmente por disposición del equipo directivo.',
                    startDate: start,
                    endDate: end
                };
            }

            if (start && today < start) {
                return {
                    active: false,
                    reason: 'future',
                    message: `El período de preinscripción comenzará el ${start.split('-').reverse().join('/')}.`,
                    startDate: start,
                    endDate: end
                };
            }

            if (end && today > end) {
                return {
                    active: false,
                    reason: 'expired',
                    message: `El período de preinscripción finalizó el ${end.split('-').reverse().join('/')}.`,
                    startDate: start,
                    endDate: end
                };
            }

            return {
                active: true,
                reason: 'active',
                message: 'Preinscripciones habilitadas',
                startDate: start,
                endDate: end
            };
        },

        getAdvancedStats(targetYear = null) {
            const enrollments = this.getAll();
            const year = targetYear ? parseInt(targetYear, 10) : new Date().getFullYear();

            // Renovación anual: filtrar estrictamente por el ciclo lectivo/año correspondiente
            const activeEnrollments = enrollments.filter(rec => {
                if (!rec.createdAt) return true;
                let recYear = null;
                if (typeof rec.createdAt === 'string' && rec.createdAt.length >= 4) {
                    recYear = parseInt(rec.createdAt.substring(0, 4), 10);
                } else {
                    recYear = new Date(rec.createdAt).getFullYear();
                }
                return isNaN(recYear) || recYear === year;
            });

            let totalStudents = activeEnrollments.length;
            let totalSubjectEnrollments = 0;
            let paidSubjectsCount = 0;
            let unpaidSubjectsCount = 0;

            let totalGradedCount = 0;
            let sumGrades = 0;

            const turnStats = {
                'Febrero / Marzo':       { total: 0, passed: 0, failed: 0, pending: 0, sumGrades: 0, gradedCount: 0 },
                'Julio / Agosto':        { total: 0, passed: 0, failed: 0, pending: 0, sumGrades: 0, gradedCount: 0 },
                'Noviembre / Diciembre': { total: 0, passed: 0, failed: 0, pending: 0, sumGrades: 0, gradedCount: 0 }
            };

            const subjectCounts = {};
            const courseCounts = {};

            const cleanSubjectDisplayName = (rawName) => {
                if (!rawName) return '';
                const trimmed = String(rawName).trim();
                const norm = trimmed.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[.:,()]/g, ' ').replace(/\s+/g, ' ').trim();
                if (norm === 'lengua extranjera ingles') return 'Lengua Extranjera (Inglés)';
                return trimmed;
            };

            const normSubKey = (rawName) => {
                const disp = cleanSubjectDisplayName(rawName);
                return disp.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[.:,]/g, ' ').replace(/\s+/g, ' ').trim();
            };

            const byNormSubject = {};

            activeEnrollments.forEach(rec => {
                if (rec.currentCourse) {
                    courseCounts[rec.currentCourse] = (courseCounts[rec.currentCourse] || 0) + 1;
                }

                if (rec.subjects && Array.isArray(rec.subjects)) {
                    rec.subjects.forEach(sub => {
                        totalSubjectEnrollments++;

                        if (sub.paid) paidSubjectsCount++;
                        else unpaidSubjectsCount++;

                        const dispName = cleanSubjectDisplayName(sub.subjectName);
                        const nKey = normSubKey(dispName);
                        if (!byNormSubject[nKey]) {
                            byNormSubject[nKey] = { name: dispName, count: 0 };
                        }
                        byNormSubject[nKey].count++;
                        subjectCounts[byNormSubject[nKey].name] = byNormSubject[nKey].count;

                        let turnKey = sub.examTurn || 'Febrero / Marzo';
                        if (turnKey === 'Julio') turnKey = 'Julio / Agosto';
                        if (turnKey === 'Diciembre') turnKey = 'Noviembre / Diciembre';
                        if (!turnStats[turnKey]) {
                            turnStats[turnKey] = { total: 0, passed: 0, failed: 0, pending: 0, sumGrades: 0, gradedCount: 0 };
                        }

                        turnStats[turnKey].total++;

                        if (sub.grade !== null && sub.grade !== undefined && !isNaN(sub.grade)) {
                            const g = parseInt(sub.grade, 10);
                            sumGrades += g;
                            totalGradedCount++;

                            turnStats[turnKey].sumGrades += g;
                            turnStats[turnKey].gradedCount++;

                            if (g >= 6) {
                                turnStats[turnKey].passed++;
                            } else {
                                turnStats[turnKey].failed++;
                            }
                        } else {
                            turnStats[turnKey].pending++;
                        }
                    });
                }
            });

            let bestTurn = '-';
            let bestPassRate = 0;

            Object.keys(turnStats).forEach(t => {
                const stat = turnStats[t];
                stat.passRate = stat.gradedCount > 0 ? Math.round((stat.passed / stat.gradedCount) * 100) : 0;
                stat.avgGrade = stat.gradedCount > 0 ? (stat.sumGrades / stat.gradedCount).toFixed(1) : 'N/A';

                if (stat.gradedCount > 0 && stat.passRate > bestPassRate) {
                    bestPassRate = stat.passRate;
                    bestTurn = t;
                }
            });

            const overallAvgGrade = totalGradedCount > 0 ? (sumGrades / totalGradedCount).toFixed(1) : 'N/A';
            const paidPercentage = totalSubjectEnrollments > 0 ? Math.round((paidSubjectsCount / totalSubjectEnrollments) * 100) : 0;

            const topSubjects = Object.entries(subjectCounts)
                .map(([name, count]) => ({ name, count }))
                .sort((a, b) => b.count - a.count);

            return {
                year,
                totalStudents,
                totalSubjectEnrollments,
                paidSubjectsCount,
                unpaidSubjectsCount,
                paidPercentage,
                overallAvgGrade,
                turnStats,
                bestTurn,
                bestPassRate,
                topSubjects,
                courseCounts
            };
        },

        getFilteredEnrollments(filters = {}) {
            const enrollments = this.getAll();
            const currentYear = new Date().getFullYear();
            const result = [];

            enrollments.forEach(rec => {
                // Renovación automática por año: excluir inscripciones de ciclos anteriores
                if (rec.createdAt) {
                    const recYear = new Date(rec.createdAt).getFullYear();
                    if (!isNaN(recYear) && recYear < currentYear) {
                        return;
                    }
                }

                let cleanName = rec.studentName;
                if (!cleanName || /^\d+$/.test(String(cleanName).trim()) || String(cleanName).startsWith('INS-')) {
                    cleanName = "Juan Pérez";
                }
                let cleanDni = rec.studentDni && String(rec.studentDni).length >= 7 ? String(rec.studentDni) : "42345678";

                if (!rec.subjects || !Array.isArray(rec.subjects)) return;

                rec.subjects.forEach(sub => {
                    // 1. Filtro por Año de la Materia (owedYear)
                    if (filters.course) {
                        const filterC = filters.course.trim().toLowerCase();
                        const subOwed = (sub.owedYear || '').trim().toLowerCase();
                        if (!subOwed.includes(filterC) && subOwed !== filterC) {
                            return;
                        }
                    }

                    // 2. Filtro por Turno de Examen (renovación por turno sin acumular llamadas anteriores)
                    const activeExamTurn = (typeof getCurrentExamTurn === 'function' ? getCurrentExamTurn() : 'Febrero / Marzo');
                    const targetTurn = (filters.examTurn || activeExamTurn).trim().toLowerCase();
                    const subT = (sub.examTurn || '').trim().toLowerCase();
                    if (targetTurn !== subT) return;

                    // 3. Filtro por Estado de Abono
                    const isPaid = !!sub.paid;
                    if (filters.paymentStatus === 'paid' && !isPaid) return;
                    if (filters.paymentStatus === 'unpaid' && isPaid) return;

                    // 4. Filtro por Estado de Calificación (Aprobado, Desaprobado, Sin Calificar)
                    const hasGrade = sub.grade !== null && sub.grade !== undefined && sub.grade !== '' && !isNaN(Number(sub.grade));
                    const numGrade = hasGrade ? Number(sub.grade) : null;
                    const isPassed = hasGrade && numGrade >= 6;
                    const isFailed = hasGrade && numGrade < 6;

                    if (filters.approvalStatus === 'pending' && hasGrade) return;
                    if (filters.approvalStatus === 'passed' && !isPassed) return;
                    if (filters.approvalStatus === 'failed' && !isFailed) return;

                    // 5. Filtro específico por Materia (dropdown de carga de notas)
                    if (filters.subjectName) {
                        const filterSN = filters.subjectName.trim().toLowerCase();
                        const subName = (sub.subjectName || '').trim().toLowerCase();
                        if (subName !== filterSN) return;
                    }

                    // 6. Búsqueda de texto (materia, alumno, DNI)
                    if (filters.subject) {
                        const q = filters.subject.trim().toLowerCase();
                        const matchSub = (sub.subjectName || '').toLowerCase().includes(q);
                        const matchName = cleanName.toLowerCase().includes(q);
                        const matchDni = cleanDni.includes(q);
                        if (!matchSub && !matchName && !matchDni) return;
                    }

                    result.push({
                        enrollmentId: rec.id,
                        studentName: cleanName,
                        studentDni: cleanDni,
                        studentEmail: rec.studentEmail,
                        studentPhone: rec.studentPhone,
                        currentCourse: rec.currentCourse,
                        subjectId: sub.id,
                        subjectName: sub.subjectName,
                        owedYear: sub.owedYear,
                        calendarYear: sub.calendarYear,
                        examTurn: sub.examTurn,
                        paid: isPaid,
                        grade: numGrade
                    });
                });
            });

            return result;
        }
    };

    // Marca en amarillo el campo que falta completar cuando el usuario intenta enviar el formulario
    function highlightFieldError(inputEl, customMsg = "Necesitas completar este campo para continuar") {
        if (!inputEl) return;
        inputEl.classList.add('field-error-warning');
        const customWrapper = inputEl.closest('.custom-select-wrapper');
        if (customWrapper) {
            customWrapper.classList.add('field-error-warning');
        }
        const parent = customWrapper ? customWrapper.parentElement : inputEl.parentElement;
        if (parent && !parent.querySelector('.field-error-msg')) {
            const msgEl = document.createElement('div');
            msgEl.className = 'field-error-msg';
            msgEl.innerHTML = `<span class="material-symbols-outlined" style="font-size: 1rem; color: #b45309;">warning</span> ${esc(customMsg)}`;
            parent.appendChild(msgEl);
        }
        if (customWrapper) {
            customWrapper.querySelector('.custom-select-trigger')?.focus();
        } else {
            inputEl.focus();
        }

        const cleanFn = () => {
            inputEl.classList.remove('field-error-warning');
            if (customWrapper) customWrapper.classList.remove('field-error-warning');
            const m = parent ? parent.querySelector('.field-error-msg') : null;
            if (m) m.remove();
            inputEl.removeEventListener('input', cleanFn);
            inputEl.removeEventListener('change', cleanFn);
        };
        inputEl.addEventListener('input', cleanFn);
        inputEl.addEventListener('change', cleanFn);
    }

    function clearFieldErrors() {
        document.querySelectorAll('.field-error-warning').forEach(el => el.classList.remove('field-error-warning'));
        document.querySelectorAll('.field-error').forEach(el => el.classList.remove('field-error'));
        document.querySelectorAll('.field-error-msg').forEach(el => el.remove());
    }

    // Estado global de la aplicación Alumnos
    const state = {
        addedSubjects: []
    };

    const COURSE_LEVEL_MAP = {
        '1ro': 1,
        '2do': 2,
        '3ro': 3,
        '4to': 4,
        '5to': 5,
        '6to': 6,
        '7mo': 7,
        'Egreso': 8
    };

    const YEAR_LEVEL_MAP = {
        '1er AÑO': 1,
        '2do AÑO': 2,
        '3er AÑO': 3,
        '4to': 4,
        '5to': 5,
        '6to': 6,
        '7mo': 7
    };

    // Arma los menús desplegables con el estilo de la página sin perder el valor del select original
    function initCustomSelect(select) {
        if (!select || select.dataset.customized === 'true') {
            if (select && select._refreshCustomOptions) {
                select._refreshCustomOptions();
            }
            return;
        }

        select.dataset.customized = 'true';
        select.classList.add('custom-select-native');

        const wrapper = document.createElement('div');
        wrapper.className = 'custom-select-wrapper';
        if (select.classList.contains('filter-item')) {
            wrapper.classList.add('filter-item');
        }

        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);

        const trigger = document.createElement('div');
        trigger.className = 'custom-select-trigger';
        trigger.tabIndex = 0;

        const labelSpan = document.createElement('span');
        labelSpan.className = 'custom-select-label';
        trigger.appendChild(labelSpan);
        wrapper.appendChild(trigger);

        const dropdown = document.createElement('div');
        dropdown.className = 'custom-select-dropdown';
        wrapper.appendChild(dropdown);

        function updateLabel() {
            const selectedOpt = select.options[select.selectedIndex];
            labelSpan.textContent = selectedOpt ? selectedOpt.textContent : '-- Seleccionar --';
            if (select.disabled) {
                labelSpan.style.color = '#ffffff';
                trigger.style.color = '#ffffff';
            } else {
                labelSpan.style.color = '#1a0505';
                trigger.style.color = '#1a0505';
            }
        }

        function renderOptions() {
            dropdown.innerHTML = '';
            if (select.disabled) {
                wrapper.classList.add('is-disabled');
                trigger.tabIndex = -1;
                trigger.style.color = '#ffffff';
                labelSpan.style.color = '#ffffff';
            } else {
                wrapper.classList.remove('is-disabled');
                trigger.tabIndex = 0;
                trigger.style.color = '#1a0505';
                labelSpan.style.color = '#1a0505';
            }

            Array.from(select.options).forEach((opt, idx) => {
                if (opt.hidden) return;
                const item = document.createElement('div');
                item.className = 'custom-select-option';
                item.textContent = opt.textContent;
                item.dataset.value = opt.value;
                item.dataset.index = idx;

                if (opt.disabled) {
                    item.classList.add('is-disabled');
                }
                if (idx === select.selectedIndex) {
                    item.classList.add('is-selected');
                }

                item.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (opt.disabled || select.disabled) return;
                    select.selectedIndex = idx;
                    select.value = opt.value;
                    updateLabel();
                    wrapper.classList.remove('open');
                    wrapper.classList.remove('field-error-warning');
                    const parent = wrapper.parentElement;
                    if (parent) {
                        const m = parent.querySelector('.field-error-msg');
                        if (m) m.remove();
                    }
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    select.dispatchEvent(new Event('input', { bubbles: true }));
                });

                dropdown.appendChild(item);
            });
            updateLabel();
        }

        select._refreshCustomOptions = renderOptions;
        renderOptions();

        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            if (select.disabled) return;
            const isOpen = wrapper.classList.contains('open');
            document.querySelectorAll('.custom-select-wrapper.open').forEach(w => {
                if (w !== wrapper) w.classList.remove('open');
            });

            if (!isOpen) {
                const rect = wrapper.getBoundingClientRect();
                const spaceBelow = window.innerHeight - rect.bottom;
                if (spaceBelow < 260 && rect.top > 260) {
                    dropdown.style.top = 'auto';
                    dropdown.style.bottom = 'calc(100% + 5px)';
                } else {
                    dropdown.style.top = 'calc(100% + 5px)';
                    dropdown.style.bottom = 'auto';
                }
            }
            wrapper.classList.toggle('open', !isOpen);
        });

        trigger.addEventListener('keydown', (e) => {
            if (select.disabled) return;
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
                e.preventDefault();
                wrapper.classList.add('open');
            } else if (e.key === 'Escape') {
                wrapper.classList.remove('open');
            }
        });

        select.addEventListener('change', () => {
            updateLabel();
            Array.from(dropdown.children).forEach(child => {
                child.classList.toggle('is-selected', child.dataset.value === select.value);
            });
        });

        const observer = new MutationObserver(() => {
            renderOptions();
        });
        observer.observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'hidden', 'selected'] });
    }

    function initAllCustomSelects() {
        const selects = document.querySelectorAll('.form-select');
        selects.forEach(s => initCustomSelect(s));
    }

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.custom-select-wrapper')) {
            document.querySelectorAll('.custom-select-wrapper.open').forEach(w => w.classList.remove('open'));
        }
    });

    document.addEventListener('reset', (e) => {
        setTimeout(() => {
            const selects = e.target.querySelectorAll('.form-select');
            selects.forEach(s => {
                if (s._refreshCustomOptions) s._refreshCustomOptions();
            });
        }, 10);
    });

    window.AppCore = {
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
        initCustomSelect,
        initAllCustomSelects,
        esc
    };
})();