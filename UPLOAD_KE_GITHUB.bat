@echo off
title Upload & Sinkronisasi ke GitHub (kemenhajkotapasuruan/e-visa)
cd /d "C:\laragon\www\e-visa"
echo ============================================================
echo   MENGUNGGAH PROJECT KE GITHUB (kemenhajkotapasuruan/e-visa)
echo ============================================================
echo.
"C:\laragon\bin\git\cmd\git.exe" add .
"C:\laragon\bin\git\cmd\git.exe" commit -m "Update data Biovisa PHU Pasuruan" 2>nul
"C:\laragon\bin\git\cmd\git.exe" branch -M main
"C:\laragon\bin\git\cmd\git.exe" push -u origin main
echo.
echo ============================================================
echo   SELESAI! Silakan cek https://github.com/kemenhajkotapasuruan/e-visa
echo ============================================================
pause
