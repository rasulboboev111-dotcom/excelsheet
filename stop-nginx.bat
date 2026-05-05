@echo off
REM Корректная остановка локального nginx + php-cgi (если запущены через start-nginx.bat).
cd /d "%~dp0"

if exist ".nginx\nginx.exe" (
    echo Stopping nginx gracefully...
    ".nginx\nginx.exe" -p ".nginx" -c "..\nginx\dev.windows.conf" -s quit 2>NUL
)

REM Если nginx не отреагировал на quit — убиваем форсированно.
taskkill /F /IM nginx.exe >NUL 2>&1
taskkill /F /IM php-cgi.exe >NUL 2>&1

echo Done.
