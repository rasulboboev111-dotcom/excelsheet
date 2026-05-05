@echo off
REM Запускает локальный nginx + php-cgi для проекта Excel.
REM nginx слушает 127.0.0.1:8000, php-cgi — 127.0.0.1:9000.
REM Закрытие этого окна = остановка обоих процессов.
REM
REM ПРЕДВАРИТЕЛЬНО:
REM   1) Запустить Redis: start-redis.bat (если включён CACHE_DRIVER=redis в .env).
REM   2) Остановить php artisan serve, если запущен (он держит порт 8000).

cd /d "%~dp0"

if not exist ".nginx\nginx.exe" (
    echo nginx not found in .nginx\
    echo Download portable nginx for Windows: http://nginx.org/en/download.html
    echo Extract zip into .nginx\
    pause
    exit /b 1
)

if not exist "C:\php82\php-cgi.exe" (
    echo php-cgi.exe not found at C:\php82\php-cgi.exe
    echo Adjust path in this .bat if your PHP is elsewhere.
    pause
    exit /b 1
)

echo Starting php-cgi.exe on 127.0.0.1:9000...
start "php-cgi" /B "C:\php82\php-cgi.exe" -b 127.0.0.1:9000 -c "C:\php82\php.ini"

echo Starting nginx on 127.0.0.1:8000...
echo.
echo Open http://127.0.0.1:8000 in browser.
echo Close this window to stop nginx + php-cgi.
echo.

REM Запускаем nginx с конфигом из nginx/dev.windows.conf
REM -p .nginx\ — рабочая директория nginx (logs, temp, conf лежат там).
REM -c — путь к конфигу относительно рабочей директории.
".nginx\nginx.exe" -p ".nginx" -c "..\nginx\dev.windows.conf" -g "daemon off;"

REM Когда nginx завершится (Ctrl+C / закрытие окна), убиваем php-cgi.
echo Stopping php-cgi...
taskkill /F /IM php-cgi.exe >NUL 2>&1
