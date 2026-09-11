#!/bin/bash

# Define paths based on your layout
BASE_DIR="/home/kali/Desktop"
PING_SCRIPT="$BASE_DIR/SERVER/ping_monitor.sh"

case "$1" in
    start)
        echo "[>] Starting mysql server"
        sudo systemctl start mysql
 
        # 1. Initialize Database, User, and Tables automatically
        echo "[>] Verifying MySQL Database and User configuration..."
        sudo mysql -e "
            CREATE DATABASE IF NOT EXISTS Labguard;
            CREATE USER IF NOT EXISTS 'exam'@'localhost' IDENTIFIED BY 'exam';
            GRANT ALL PRIVILEGES ON Labguard.* TO 'exam'@'localhost';
            FLUSH PRIVILEGES;
            
            USE Labguard;
            CREATE TABLE IF NOT EXISTS internet (
                ip VARCHAR(50) PRIMARY KEY,
                status VARCHAR(20),
                time DATETIME
            );
            CREATE TABLE IF NOT EXISTS usb (
                ip VARCHAR(50) PRIMARY KEY,
                status VARCHAR(20),
                device VARCHAR(100),
                time DATETIME
            );
        "
        if [ $? -eq 0 ]; then
            echo "[>] Database initialized successfully"
        else
            echo "[!] Database initialization failed! Ensure MySQL is installed and running."
            exit 1
        fi
        
        # 2. Kill any existing instances first so we don't get duplicates
        pkill -f "php -S 0.0.0.0:8000"
        pkill -f "ping_monitor.sh"
        
        # 3. Make sure the ping script is executable
        chmod +x "$PING_SCRIPT" 2>/dev/null

        # 4. Start the PHP server in the background
        nohup php -S 0.0.0.0:8000 -t "$BASE_DIR" > /dev/null 2>&1 &
        echo "[>] PHP Web Server started on http://localhost:8000"

        # 5. Start the Ping Monitor loop in the background
        nohup bash -c "while true; do $PING_SCRIPT; sleep 5; done" > /dev/null 2>&1 &
        echo "[>] Ping Monitor background loop started with PID $!"
        echo ""
        echo "[+] Labguard is now LIVE. You can close this terminal."
        ;;
        
    stop)
        echo "[>] Stopping Labguard Exam Environment..."
        
        # Kill the PHP server
        pkill -f "php -S 0.0.0.0:8000"
        echo  "[>] Server is closed"
        
        # Kill the ping monitor loop and script
        pkill -9 -f "ping_monitor.sh"
        echo "[>] ping monitor closed"
        
        # Clean up temporary C2 files
        rm -rf /tmp/labsentinel_cmds/* 2>/dev/null
        rm -rf /tmp/labsentinel_pings/* 2>/dev/null
        echo "[>] Cleared temporary C2 queue and heartbeat files"
        
        echo ""
        echo "[+] Labguard processes have stopped."
        ;;
        
    *)
        echo "Usage: labguard {start|stop}"
        echo ""
        echo "Examples:"
        echo "  labguard start   -> Initializes DB, starts dashboard & ping monitor"
        echo "  labguard stop    -> Kills all background processes"
        exit 1
        ;;
esac
