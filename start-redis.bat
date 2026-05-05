@echo off
REM Локальный dev-Redis для проекта Excel.
REM Запускает portable redis-server.exe из .redis/ в foreground.
REM Закрытие окна = остановка Redis.
REM
REM Параметры:
REM   --maxmemory 256mb            : жёсткий лимит RAM
REM   --maxmemory-policy allkeys-lru : при переполнении выбрасывать самые старые
REM   --save ""                    : без RDB-снапшотов на диск (in-memory only)
REM   --appendonly no              : без AOF-журнала
REM
REM Для прода — НЕ использовать эти флаги. Там нужен персистент: см. docs/PERFORMANCE.md.

cd /d "%~dp0"

if not exist ".redis\redis-server.exe" (
    echo Redis binaries not found in .redis\
    echo Download portable Redis: https://github.com/microsoftarchive/redis/releases
    echo Extract Redis-x64-3.0.504.zip into .redis\
    pause
    exit /b 1
)

echo Starting Redis on 127.0.0.1:6379 ^(in-memory, max 256MB^)...
echo Close this window to stop Redis.
echo.
".redis\redis-server.exe" --maxmemory 256mb --maxmemory-policy allkeys-lru --save "" --appendonly no
