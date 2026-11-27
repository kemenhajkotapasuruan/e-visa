@echo off
title Upload & Sinkronisasi ke GitHub (kemenhajkotapasuruan/e-visa)
cd /d "C:\laragon\www\e-visa"
echo ============================================================
echo   1. MENYUSUN TAMPILAN IDENTIK & DATA TERBARU GITHUB PAGES
echo ============================================================
"C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe" build_github_pages.php
echo.
echo ============================================================
echo   2. MENGUNGGAH KE GITHUB (kemenhajkotapasuruan.github.io/e-visa)
echo ============================================================
echo.
"C:\laragon\bin\git\cmd\git.exe" add -A
"C:\laragon\bin\git\cmd\git.exe" commit -m "Update tampilan identik, data Biovisa & Auto-Siskohat GitHub Pages" 2>nul
"C:\laragon\bin\git\cmd\git.exe" branch -M main
"C:\laragon\bin\git\cmd\git.exe" push -u --force origin main
echo.
echo ============================================================
echo   SELESAI! Silakan buka https://kemenhajkotapasuruan.github.io/e-visa/
echo ============================================================
pause
