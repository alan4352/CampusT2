#!/bin/sh
# Inicia ProgMesas (programas de examen) en el servidor. Ejemplo para dejarlo corriendo:
#   nohup ./iniciar.sh > progmesas.log 2>&1 &
cd "$(dirname "$0")"
[ -f .env ] || { echo "Falta el archivo .env: renombrar env-campus.txt a .env en esta carpeta."; exit 1; }
[ -d node_modules ] || npm install --omit=dev || exit 1
exec npm start
