#!/bin/bash

# Configuration Variables
PHP_PORT="8080"
DOC_ROOT="/home/exam/Desktop/Server"
DB_NAME="Labguard"
DB_USER="exam"
DB_PASS="exam"
PING_SCRIPT="/home/exam/Desktop/Server/ping_monitor.sh" # Update this to your actual ping script path

echo "[+] Starting LabSentinel Environment Setup..."

# -------------------------------------------------------------
# 1. MySQL User & Database Configuration
# -------------------------------------------------------------
echo "[*] Configuring MySQL Database and Privileges..."

sudo mysql -u root <<EOF
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF

if [ $? -eq 0 ]; then
    echo "[✓] MySQL database '${DB_NAME}' and user '${DB_USER}' configured successfully."
else
    echo "[-] Error configuring MySQL. Please check your MySQL root access."
    exit 1
fi

# -------------------------------------------------------------
# 2. Start PHP Built-in Server
# -------------------------------------------------------------
echo "[*] Starting PHP development server on port ${PHP_PORT}..."

# Check if something is already running on the port
if ss -tln | grep -q ":${PHP_PORT} "; then
    echo "[-] Warning: Port ${PHP_PORT} is already in use. Killing existing PHP server..."
    fuser -k ${PHP_PORT}/tcp
fi

# Start PHP server in the background
nohup php -S 0.0.0.0:${PHP_PORT} -t "${DOC_ROOT}" > /tmp/php_server.log 2>&1 &

if [ $? -eq 0 ]; then
    echo "[✓] PHP server started successfully (Root: ${DOC_ROOT}, Port:${PHP_PORT})."
else
    echo "[-] Failed to start PHP server."
    exit 1
fi

# -------------------------------------------------------------
# 3. Run the Device Ping / Telemetry Script
# -------------------------------------------------------------
if [ -f "${PING_SCRIPT}" ]; then
    echo "[*] Launching device ping script..."
    nohup bash "${PING_SCRIPT}" > /tmp/lab_ping.log 2>&1 &
    echo "[✓] Ping script running in the background."
else
    echo "[-] Warning: Ping script not found at '${PING_SCRIPT}'. Please update the path in this setup script."
fi

echo "======================================================="
echo "[✓] LabSentinel Infrastructure is fully operational!"
echo "    -> Dashboard URL: http://<192.168.5.1>:${PHP_PORT}"
echo "    -> PHP Logs:      /tmp/php_server.log"
echo "    -> Ping Logs:     /tmp/lab_ping.log"
echo "======================================================="
