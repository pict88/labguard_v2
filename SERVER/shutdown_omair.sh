#!/bin/bash

PHP_PORT="8080"

echo "[+] Shutting down LabSentinel Environment ..."

# -------------------------------------------------------------
# 1. Stop the PHP Built-in Server
# -------------------------------------------------------------
if ss -tln | grep -q ":${PHP_PORT} "; then
    echo "[*] Stopping PHP server on port ${PHP_PORT}..."
    fuser -k ${PHP_PORT}/tcp
    echo "[✓] PHP server stopped."
else
    echo "[i] PHP server is not running on port ${PHP_PORT}."
fi

# -------------------------------------------------------------
# 2. Stop the Device Ping / Telemetry Script
# -------------------------------------------------------------
if pgrep -f "bash.*ping" > /dev/null; then
    echo "[*] Terminating background ping/telemetry script..."
    pkill -f "bash.*ping"
    echo "[✓] Ping script stopped."
else
    echo "[i] No active background ping scripts found."
fi

# -------------------------------------------------------------
# 3. Stop MySQL Service
# -------------------------------------------------------------
echo "[*] Stopping MySQL service..."
sudo systemctl stop mysql

if [ $? -eq 0 ]; then
    echo "[✓] MySQL service stopped successfully."
else
    echo "[-] Failed to stop MySQL service or it was already stopped."
fi

echo "======================================================="
echo "[✓] LabSentinel Environment and MySQL have been safely shut down."
echo "======================================================="
