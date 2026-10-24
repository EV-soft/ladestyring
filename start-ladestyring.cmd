@echo off
rem start-ladestyring.cmd - opretter tjekket hvert 15. minut (Opgavestyring) og aabner siden
set PHPDIR=C:\php
if not exist "%~dp0data\settings.json" "%PHPDIR%\php.exe" "%~dp0ladestyring-setpass.php"
schtasks /Create /F /SC MINUTE /MO 15 /TN "Ladestyring" /TR "\"%PHPDIR%\php-win.exe\" \"%~dp0ladestyring-cron.php\""
echo.
echo Siden: http://localhost:8890/ladestyring.php  (luk vinduet for at stoppe siden - tjekket fortsaetter)
start "" http://localhost:8890/ladestyring.php
"%PHPDIR%\php.exe" -S localhost:8890 -t "%~dp0." "%~dp0router.php"
