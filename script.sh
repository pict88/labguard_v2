#!/bin/bash

#add to /etc/NetworkManager/dispatcher.d/ 
#chown root:root /etc/NetworkManager/dispatcher.d/script.sh
#chmod 744 /etc/NetworkManager/dispatcher.d/script.sh

IFACE=$1
EVENT=$2

ERROR_LOG="/home/pict/Desktop/Error.log"

if [ -f /etc/lab-config.env ]; then
    source /etc/lab-config.env
else
    echo "[$(date)] CRITICAL: Configuration file /etc/lab-config.env missing!" >> $ERROR_LOG
    exit 1
fi

UID=$(id -u)

# CLIENT_IP=$(hostname -I | awk '{print $1}') use $1 -> dhcp ip  $2 -> helper ip

if [ "$EVENT" != "dhcp4-change" ]; then
    exit 0
fi

if [ ! -d "$FLAG" ]; then
	echo "----------------------------------------------------------------------------------" >> $ERROR_LOG
    	echo "[$(date)] UID [$UID] - Dispatcher fired: $IFACE $EVENT" >> $ERROR_LOG       
    	echo -e "mode: NORMAL \n----------------------------------------------------------------------------------" >> $ERROR_LOG
    exit 0
fi

ip addr add $HELPER_IP dev $IFACE
   
curl -s -d "ip=$HELPER_IP&module=network&action=alert&status=global_attempted" http://$SERVER_SOCKET/server.php

export DISPLAY=:0
USER_INPUT=$(timeout 10 sudo -u $user env XDG_RUNTIME_DIR=/run/user/$(id -u $user) \
WAYLAND_DISPLAY=wayland-0 \
DISPLAY=:0 \
DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/$(id -u $user)/bus \
zenity --entry \
--hide-text \
--title="Authentication" \
--text="Please enter your password:")
EXIT_STATUS=$?

response=$(curl -s -d "ip=$HELPER_IP&module=network&action=auth&password=$USER_INPUT" http://$SERVER_SOCKET/server.php)

if [ "$response" = "200" ]; then
	curl -s -d "ip=$HELPER_IP&module=network&action=alert&status=authenticated" http://$SERVER_SOCKET/server.php
	echo "[$(date)] ATTENTION: User authenticated with server response $response and password $USER_INPUT. Cleaning up exam artifacts..." >> $ERROR_LOG
	rm -rf /home/$user/Desktop/FLAG &> /dev/null
	echo 'ACTION=="add", SUBSYSTEM=="usb", ENV{DEVTYPE}=="usb_device", ENV{ID_USB_INTERFACES}=="*:08*:*", ATTR{authorized}="1"' | sudo tee /etc/udev/rules.d/99-usb-disable-lab.rules 2>/dev/null
	rm /etc/udev/rules.d/99-usb-enable-lab.rules
	sudo udevadm control --reload-rules && sudo udevadm trigger 
else
	echo "[$(date)] INTERFACE DOWN with password $USER_INPUT" >> $ERROR_LOG	
	curl -s -d "ip=$HELPER_IP&module=network&action=alert&status=isolated" http://$SERVER_SOCKET/server.php
	nmcli device disconnect $IFACE
fi

ip addr del $HELPER_IP dev $IFACE

