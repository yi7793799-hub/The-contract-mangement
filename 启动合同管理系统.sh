#!/bin/bash

# 智链经营 一键启动脚本

echo "========================================"
echo "   智链经营 一键启动"
echo "========================================"
echo ""

PHPTUTORIAL="/d/phpStudy/PHPTutorial"
APACHE="$PHPTUTORIAL/Apache/bin/httpd.exe"
MYSQL="$PHPTUTORIAL/MySQL/bin/mysqld.exe"

# 检查文件是否存在
if [ ! -f "$APACHE" ]; then
    echo "[错误] 未找到 Apache，请确认 phpStudy 安装路径"
    exit 1
fi

if [ ! -f "$MYSQL" ]; then
    echo "[错误] 未找到 MySQL，请确认 phpStudy 安装路径"
    exit 1
fi

# 启动 MySQL
echo "[1/2] 正在启动 MySQL..."
taskkill //F //IM mysqld.exe >/dev/null 2>&1
start /B "MySQL" "$MYSQL" --defaults-file="$PHPTUTORIAL/MySQL/my.ini"
sleep 3

# 启动 Apache
echo "[2/2] 正在启动 Apache..."
taskkill //F //IM httpd.exe >/dev/null 2>&1
start /B "Apache" "$APACHE"
sleep 2

# 检查是否启动成功
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1/ 2>/dev/null)

if [ "$HTTP_CODE" = "200" ]; then
    echo ""
    echo "========================================"
    echo "   启动成功！"
    echo "   请访问: http://127.0.0.1/"
    echo "========================================"
else
    echo ""
    echo "[警告] 请检查服务是否正常启动"
fi

echo ""
echo "按回车键退出..."
read -r
