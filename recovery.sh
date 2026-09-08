#!/bin/bash


ERROR_LOG=/home/pict/Desktop/Error.log

if [ -f /etc/lab-config.env ]; then
    source /etc/lab-config.env
else
    echo "[-] CRITICAL: Configuration file /etc/lab-config.env missing!" >> $ERROR_LOG
    exit 1
fi

echo "Start time: $(date '+%H:%M:%S')" >> $ERROR_LOG
 
sleep 15

echo "End time:   $(date '+%H:%M:%S')" >> $ERROR_LOG

#PROFILE=$(nmcli -t -f NAME connection show --active | head -n 1)
#HELPER_IP="192.168.50.3/24"
PROFILE="Wired Connection 1"

echo "CHECKING FLAG-------" >> $ERROR_LOG

if [ ! -d /home/$user/Desktop/FLAG ]; then
	exit 0
fi

echo "CHECKED FLAG--------" >> $ERROR_LOG

ip addr flush dev lo scope global 2>/dev/null || true	
sudo nmcli connection modify "$PROFILE" ipv4.method manual ipv4.addresses "$HELPER_IP"

nmcli connection reload

nmcli connection down "$PROFILE"
nmcli connection up "$PROFILE"
