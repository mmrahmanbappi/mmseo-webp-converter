@echo off
set P=F:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe
del F:\laragon\qa2.log 2>nul
for %%S in (reset setup unit cycle1 cycle2 cycle3 security stress extras) do (
  echo ######## %%S >> F:\laragon\qa2.log
  %P% F:\laragon\qa2.php %%S >> F:\laragon\qa2.log 2>&1
)
echo ALLDONE >> F:\laragon\qa2.log
