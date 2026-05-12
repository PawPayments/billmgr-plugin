#!/bin/bash
BASEDIR=$(dirname "$0")
test -f "${BASEDIR}/.pawpayments" || exit 1
cp -r "${BASEDIR}/etc" /usr/local/mgr5/
cp -r "${BASEDIR}/paymethods" /usr/local/mgr5/
cp -r "${BASEDIR}/cgi" /usr/local/mgr5/
cp -r "${BASEDIR}/include" /usr/local/mgr5/
cp -r "${BASEDIR}/skins" /usr/local/mgr5/
chmod 755 /usr/local/mgr5/paymethods/pmpawpayments
chmod 755 /usr/local/mgr5/cgi/pawpayments*.php
killall core 2>/dev/null
echo "PawPayments BillManager plugin installed"
