@echo off
chcp 65001 >nul
title 合同管理系统 - 启动器

echo ========================================
echo    合同管理系统 一键启动
echo ========================================
echo.

:: 检查 phpStudy 是否存在
if not exist "D:\phpStudy\PHPTutorial\Apache\bin\httpd.exe" (
    echo [错误] 未找到 Apache，请确认 phpStudy 安装路径
    pause
    exit /b 1
)

if not exist "D:\phpStudy\PHPTutorial\MySQL\bin\mysqld.exe" (
    echo [错误] 未找到 MySQL，请确认 phpStudy 安装路径
    pause
    exit /b 1
)

:: 启动 MySQL
echo [1/2] 正在启动 MySQL...
taskkill /F /IM mysqld.exe >nul 2>&1
start /B "MySQL" "D:\phpStudy\PHPTutorial\MySQL\bin\mysqld.exe" --defaults-file="D:\phpStudy\PHPTutorial\MySQL\my.ini"
timeout /t 3 /nobreak >nul

:: 检查 MySQL 是否启动成功
"D:\phpStudy\PHPTutorial\MySQL\bin\mysql.exe" -u root -p123456aa -e "SELECT 1" >nul 2>&1
if %errorlevel% neq 0 (
    echo [警告] MySQL 启动可能有问题，请检查
)

:: 启动 Apache
echo [2/2] 正在启动 Apache...
taskkill /F /IM httpd.exe >nul 2>&1
start /B "Apache" "D:\phpStudy\PHPTutorial\Apache\bin\httpd.exe"

timeout /t 2 /nobreak >nul

:: 检查 Apache 是否启动成功
curl -s -o nul -w "%%{http_code}" http://127.0.0.1/ | find "200" >nul 2>&1
if %errorlevel% equ 0 (
    echo.
    echo ========================================
    echo    启动成功！
    echo    请访问: http://127.0.0.1/
    echo ========================================
) else (
    echo.
    echo [警告] 请检查 Apache 是否正常启动
)

echo.
echo 按任意键退出...
pause >nul
