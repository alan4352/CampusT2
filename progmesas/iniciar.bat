@echo off
rem Inicia ProgMesas (programas de examen). Dejar esta ventana abierta mientras se use.
cd /d "%~dp0"
if not exist .env (
    echo Falta el archivo .env: renombra env-campus.txt a .env en esta carpeta.
    pause
    exit /b 1
)
if not exist node_modules (
    echo Instalando ProgMesas por primera vez...
    call npm install --omit=dev || (pause & exit /b 1)
)
call npm start
pause
