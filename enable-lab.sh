#!/bin/bash

if [ -f /etc/lab-config.env ]; then
    source /etc/lab-config.env
else
    echo "[-] CRITICAL: Configuration file /etc/lab-config.env missing!" 
    exit 1
fi

PROFILE=$(nmcli -t -f NAME connection show --active | head -n 1)

if [ -d "$FLAG" ]; then
    echo "[!] ERROR: Lab is already active! You cannot re-initialize or bypass the exam state."
    exit 1
fi

function rollback(){
	echo "[-] Atomicity trigger: Reverting all lab configurations..."
	echo "-------ROLLBACK TRIGERRED-------" >> $ERROR_LOG
	echo "DUE TO $1" >> $ERROR_LOG
  
	nmcli connection modify "$PROFILE" ipv4.method auto ipv4.addresses "" 1>/dev/null 2>>$ERROR_LOG
	nmcli connection up "$PROFILE" 1>/dev/null 2>>$ERROR_LOG
	
	echo "[-] Rollback complete. Lab is safely reverted"
	echo "--------------------------------" >> $ERROR_LOG
	exit 1
}

function static_network(){
	ip addr flush dev lo scope global 2>/dev/null || true	
	nmcli connection modify "$PROFILE" ipv4.method manual ipv4.addresses "$HELPER_IP" &> /dev/null
	nmcli connection up "$PROFILE" &> /dev/null

}

function usb_rule(){
	echo 'ACTION=="add", SUBSYSTEM=="usb", ENV{DEVTYPE}=="usb_device", ENV{ID_USB_INTERFACES}=="*:08*:*", ATTR{authorized}="0", RUN+="/usr/bin/systemd-run --no-block /usr/local/bin/usb-auth.sh /sys%p"' | sudo tee /etc/udev/rules.d/99-usb-enable-lab.rules
	sudo udevadm control --reload-rules && sudo udevadm trigger 
}

function check_usb_rule(){
	if [ -f /etc/udev/rules.d/99-usb-enable-lab.rules ]; then
		echo "[+] USB Rule file found !"
	else
		echo "[!] ERROR: udev file not present check /etc/udev/rules.d/"
		echo "   [>] check /etc/udev/rules.d/"
		rollback "USB FILE DOES NOT EXIST"
	fi
}

function check_isolation(){
  if ! ping -c 2 www.google.com &>/dev/null; then
	  echo "[+] Isolation Successful"
  else
	  echo "[!] ERROR: Isolation is not successful"
	  rollback "PING FAIL"
fi
}

{ mkdir "$FLAG" 1>/dev/null 2>>$ERROR_LOG && echo "[+] Mode: EXAM"; } || echo "[-] ERROR: Directory creation failed"

{ static_network 1>/dev/null 2>>$ERROR_LOG  && echo "[+] Network Module Started"; } || { echo "[-] ERROR: Network Module failed"; rollback "NETWORK_ERROR"; }

{ usb_rule 1>/dev/null 2>>$ERROR_LOG  && echo "[+] USB Module Started"; } || { echo "[-] ERROR: USB Module failed"; rollback "USB_ERROR"; }


check_isolation
check_usb_rule

if curl -s --max-time 3 "http://$SERVER_SOCKET/server.php" &>/dev/null; then
	echo "[+] Connection with server established successfully"
else
	echo "[!] ERROR: CONNECTION WITH SERVER NOT ESTABLISHED"
	echo "   [>] Ensure central server is running"
	echo "   [>] run \"sudo disable-lab\" to avoid loops and try again"
fi
