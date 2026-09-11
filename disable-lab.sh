#!/bin/bash

TARGET_HASH="2e4fecd347583f282eb459bf3ecc0367d5d680cc0d81286a9110acc558fd5b19"

read -s -p "Enter password: " USER_INPUT
echo ""

INPUT_HASH=$(echo -n "$USER_INPUT" | sha256sum | awk '{print $1}')

if [ "$INPUT_HASH" != "$TARGET_HASH" ]; then
    echo "[-] Access Denied: Incorrect password."
    exit 1
fi

if [ -f /etc/lab-config.env ]; then
    source /etc/lab-config.env
else
    echo "[-] CRITICAL: Configuration file /etc/lab-config.env missing!" >&2
    exit 1
fi

if [ ! -d "$FLAG" ]; then
	echo "FLAG not present"
  exit 0
fi

IFACE=$(nmcli -t -f DEVICE,STATE connection show --active | grep ':activated' | grep -v '^lo:' | head -n 1 | cut -d: -f1)
PROFILE=$(nmcli -t -f GENERAL.CONNECTION device show "$IFACE"  | awk -F':' '{print $2}') 1>/dev/null 2>>$ERROR_LOG

function dynamic_network(){	
	ip addr flush dev lo scope global 2>/dev/null || true	
	nmcli connection modify "$PROFILE" ipv4.method auto ipv4.addresses "" ipv4.gateway "" ipv4.dns "" ipv4.ignore-auto-dns no 1>/dev/null 2>>$ERROR_LOG	
	nmcli connection reload	1>/dev/null 2>>$ERROR_LOG
	nmcli connection down "$PROFILE" 1>/dev/null 2>>$ERROR_LOG || true
	nmcli connection up "$PROFILE" 1>/dev/null 2>>$ERROR_LOG
}

function usb_rule_disable(){
	echo 'ACTION=="add", SUBSYSTEM=="usb", ENV{DEVTYPE}=="usb_device", ENV{ID_USB_INTERFACES}=="*:08*:*", ATTR{authorized}="1"' | sudo tee /etc/udev/rules.d/99-usb-disable-lab.rules 2>/dev/null
	rm /etc/udev/rules.d/99-usb-enable-lab.rules
	sudo udevadm control --reload-rules && sudo udevadm trigger 
}

{ curl -S -d "ip=$HELPER_IP&module=network&action=alert&status=LAB_EXIT" http://$SERVER_SOCKET/server.php 1>/dev/null 2>>$ERROR_LOG && echo "[+] Server Updated"; } || echo "[-] ERROR: Server not updated"  

{ rmdir $FLAG 1>/dev/null 2>>$ERROR_LOG && echo "[+] Mode: NORMAL"; } || echo "[-] ERROR: Directory deletion failed [ maybe mode:NORMAL before itself ] [ CHECK LOGS ]"

{ dynamic_network && echo "[+] Network Module Dropped"; } || echo "[-] ERROR: Network Module Drop failed [ check nmcli connections or switch to automatic manually ] [ CHECK LOGS ]"

{ usb_rule_disable && echo "[+] USB Module Reverted to 1"; } || echo "[-] ERROR: USB Module Drop failed [ check /etc/udev/rules.d/ ] [ CHECK LOGS ]"

if ping -q -c 2 www.google.com &>/dev/null; then
	echo "[+] Internet connectivity restored"
else
	echo "[!] ERROR: No Internet connection"
	echo "   [->] TRY SWITCHING TO AUTOMATIC MANUALLY"
fi
