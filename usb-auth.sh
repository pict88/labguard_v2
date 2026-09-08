#!/bin/bash

SYS_PATH=$1
AUTH_FILE="${SYS_PATH}/authorized"

if [ -f /etc/lab-config.env ]; then
    source /etc/lab-config.env
else
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [-] CRITICAL: /etc/lab-config.env missing!" >> /home/ubuntu/Desktop/Usb.log
    exit 1
fi

LOG_FILE="$ERROR_LOG"

log_event() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"
}

touch "$LOG_FILE"
chmod 644 "$LOG_FILE"

if [ ! -f "$AUTH_FILE" ]; then
    exit 0
fi

VID=$(cat "$SYS_PATH/idVendor" 2>/dev/null)
PID=$(cat "$SYS_PATH/idProduct" 2>/dev/null)
DEVICE_ID="${VID}:${PID}"

log_event "ALERT: Unauthorized USB Device inserted at $SYS_PATH. Prompting for password."

# 1. State: IDLE / INSERTED (Keep blocked & alert server)
echo 0 > "$AUTH_FILE"

curl -s -d "ip=$HELPER_IP&module=usb&action=alert&status=idle&device=$DEVICE_ID" \
    "http://$SERVER_SOCKET/server.php" >/dev/null 2>&1

sleep 1

# Zenity popup 
export DISPLAY=:0
USER_INPUT=$(timeout 15 sudo -u $user env XDG_RUNTIME_DIR=/run/user/$(id -u $user) \
WAYLAND_DISPLAY=wayland-0 \
DISPLAY=:0 \
DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/$(id -u $user)/bus \
zenity --entry \
--hide-text \
--title="USB Security Lock" \
--text="Unauthorized USB Storage Device detected.\nPlease enter password to mount:")

EXIT_STATUS=$?

# Evaluate Password via Server
if [ $EXIT_STATUS -eq 0 ] && [ -n "$USER_INPUT" ]; then
    RESPONSE=$(curl -s \
        -d "ip=$HELPER_IP" \
        -d "module=usb" \
        -d "action=auth" \
        --data-urlencode "password=$USER_INPUT" \
        -d "device=$DEVICE_ID" \
        "http://$SERVER_SOCKET/server.php" 2>/dev/null)

    if [ "$RESPONSE" = "200" ]; then
        # 2. State: AUTHORIZED
        echo 1 > "$AUTH_FILE"
        curl -s -d "ip=$HELPER_IP&module=usb&action=alert&status=authorized&device=$DEVICE_ID" \
            "http://$SERVER_SOCKET/server.php" >/dev/null 2>&1
        log_event "SUCCESS: Correct password entered. USB authorized and mounted."
    else
        # 3. State: BLOCKED (Authentication failed)
        echo 0 > "$AUTH_FILE"
        curl -s -d "ip=$HELPER_IP&module=usb&action=alert&status=blocked&device=$DEVICE_ID" \
            "http://$SERVER_SOCKET/server.php" >/dev/null 2>&1
        log_event "BLOCKED: Incorrect password entered. USB remains locked."
    fi
else
    # 3. State: BLOCKED (Timeout/Cancel)
    echo 0 > "$AUTH_FILE"
    curl -s -d "ip=$HELPER_IP&module=usb&action=alert&status=blocked&device=$DEVICE_ID" \
        "http://$SERVER_SOCKET/server.php" >/dev/null 2>&1

    if [ $EXIT_STATUS -eq 124 ]; then
        log_event "BLOCKED: Prompt timed out after 15 seconds. USB remains locked."
    else
        log_event "BLOCKED: User clicked Cancel. USB remains locked."
    fi
fi
