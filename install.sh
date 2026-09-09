#!/bin/bash

if [ "$EUID" -ne 0 ]; then
  echo "[-] ERROR: This installation script must be run as root (use sudo)"
  exit 1
fi

echo "Changing to root"
chown root:root *.sh *.env

echo "Updating privileges"
chmod 744 *.sh

echo "Moving script.sh to /etc/NetworkManager/dispatcher.d/"
mv script.sh /etc/NetworkManager/dispatcher.d/

echo "Moving enable-lab.sh disable-lab.sh recovery.sh to /usr/local/bin/"
mv enable-lab.sh disable-lab.sh recovery.sh usb-auth.sh /usr/local/bin/

echo "Moving lab-config.env to /etc/"
mv lab-config.env /etc/

echo "installation done successfully"
