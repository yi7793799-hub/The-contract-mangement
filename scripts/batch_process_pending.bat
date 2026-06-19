@echo off
chcp 65001 >nul
echo ========================================
echo   批量处理待导入任务
echo ========================================
echo.

set PHP_PATH=D:\phpStudy\PHPTutorial\php\php-7.2.1-nts\php.exe
set WORKER=E:\The contract mangement\resource code\scripts\import-worker.php

echo 正在处理所有待导入任务...
echo.

%PHP_PATH% %WORKER% 94
%PHP_PATH% %WORKER% 93
%PHP_PATH% %WORKER% 92
%PHP_PATH% %WORKER% 89
%PHP_PATH% %WORKER% 87
%PHP_PATH% %WORKER% 85
%PHP_PATH% %WORKER% 81
%PHP_PATH% %WORKER% 80
%PHP_PATH% %WORKER% 79
%PHP_PATH% %WORKER% 78
%PHP_PATH% %WORKER% 70
%PHP_PATH% %WORKER% 68
%PHP_PATH% %WORKER% 66
%PHP_PATH% %WORKER% 65
%PHP_PATH% %WORKER% 62
%PHP_PATH% %WORKER% 61
%PHP_PATH% %WORKER% 60
%PHP_PATH% %WORKER% 59
%PHP_PATH% %WORKER% 58
%PHP_PATH% %WORKER% 57
%PHP_PATH% %WORKER% 56
%PHP_PATH% %WORKER% 55
%PHP_PATH% %WORKER% 54
%PHP_PATH% %WORKER% 53
%PHP_PATH% %WORKER% 52
%PHP_PATH% %WORKER% 50
%PHP_PATH% %WORKER% 44
%PHP_PATH% %WORKER% 43
%PHP_PATH% %WORKER% 41
%PHP_PATH% %WORKER% 39
%PHP_PATH% %WORKER% 38
%PHP_PATH% %WORKER% 37
%PHP_PATH% %WORKER% 36

echo.
echo ========================================
echo   所有任务处理完成
echo ========================================
pause
