// Vuelve a autorizar el acceso a Google Drive y guarda backend/token.json.
//
//   node scripts/autorizar_drive.js
//
// 1. Abrí el enlace que aparece, iniciá sesión con la cuenta dueña de la carpeta de programas y aceptá.
// 2. El navegador va a redirigir a http://localhost/?code=... (puede decir que la página no carga: es normal).
// 3. Copiá la dirección completa de la barra (o sólo el valor de "code") y pegala acá.
//
// IMPORTANTE: si en Google Cloud Console la app OAuth está en modo "Testing", Google invalida el permiso
// cada 7 días. Para que no venza, en "Pantalla de consentimiento de OAuth" pasala a "En producción".

import fs from 'fs';
import readline from 'readline';
import { oAuth2Client } from '../drive.js';
import { RUTAS, DRIVE_ALCANCE, DRIVE_PERMISO } from '../config.js';

if (!oAuth2Client) {
    console.error('No se encontró backend/oauth.json con las credenciales de la app de Google.');
    process.exit(1);
}

const url = oAuth2Client.generateAuthUrl({
    access_type: 'offline',
    prompt: 'consent',
    scope: [DRIVE_PERMISO]
});

console.log(`\nPermiso pedido: ${DRIVE_PERMISO} (DRIVE_ALCANCE=${DRIVE_ALCANCE})`);
console.log('\nAbrí este enlace en el navegador:\n');
console.log(url, '\n');

const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
rl.question('Pegá la dirección a la que te redirigió (o el código): ', async (respuesta) => {
    rl.close();
    let code = respuesta.trim();
    try {
        const u = new URL(code);
        code = u.searchParams.get('code') || code;
    } catch {
        // no era una URL: se usa tal cual
    }
    try {
        const { tokens } = await oAuth2Client.getToken(code);
        fs.writeFileSync(RUTAS.token, JSON.stringify(tokens, null, 2));
        console.log('\nListo: se guardó backend/token.json. Reiniciá el servidor.');
        if (tokens.refresh_token_expires_in) {
            const dias = Math.round(tokens.refresh_token_expires_in / 86400);
            console.warn(`Atención: este permiso vence en ${dias} días porque la app está en modo "Testing".`);
        }
    } catch (err) {
        console.error('No se pudo obtener el token:', err.message);
        process.exitCode = 1;
    }
});
