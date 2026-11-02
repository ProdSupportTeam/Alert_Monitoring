@echo off
title Lumen Dev Server
cd /d %~dp0

echo ================================
echo   Starting Lumen Development Server
echo ================================
echo.

php -S localhost:8000 -t public

pause