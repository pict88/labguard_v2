#!/bin/bash
#sudo bash -c "while true; do /home/exam/Desktop/SERVER/ping_monitor.sh; sleep 5; done"

DB_HOST="127.0.0.1"
DB_USER="exam"
DB_PASS="exam"
DB_NAME="Labguard"

check_ip() {
    local n=$1
    CLEAN_IP="192.168.5.$n"
    FULL_IP="192.168.5.$n/24"

    STATUS=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -D "$DB_NAME" -N -B -e "SELECT status FROM internet WHERE ip='$FULL_IP';" 2>/dev/null)

    if ping -c 1 -W 1 "$CLEAN_IP" > /dev/null 2>&1; then
        mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -D "$DB_NAME" -e "INSERT INTO internet (ip, status, time) VALUES ('$FULL_IP', 'online', NOW()) ON DUPLICATE KEY UPDATE status='online', time=NOW();" 2>/dev/null
        if [ "$STATUS" != "online" ]; then
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] $CLEAN_IP is ONLINE"
        fi
    else
        if [ "$STATUS" = "isolated" ]; then
            return
        elif [ "$STATUS" != "offline" ]; then
            mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -D "$DB_NAME" -e "INSERT INTO internet (ip, status, time) VALUES ('$FULL_IP', 'offline', NOW()) ON DUPLICATE KEY UPDATE status='offline', time=NOW();" 2>/dev/null
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] $CLEAN_IP is OFFLINE"
        fi
    fi
}

# Launch all IP checks in parallel
for n in {2..20}; do
    check_ip "$n" &
done

# Wait for all 19 parallel checks to finish instantly
wait
