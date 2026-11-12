@echo off
rem Starts CARAT.CO on this PC. XAMPP's MySQL must be running first.
cd /d "%~dp0"
if not exist .next\BUILD_ID call npm run build
start "CARAT.CO server" cmd /k npm run api
timeout /t 3 /nobreak >nul
start "" http://caratco.localhost:3105
npm run start
