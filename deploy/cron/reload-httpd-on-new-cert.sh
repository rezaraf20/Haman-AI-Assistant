#!/bin/bash
#
# Reload Apache when a certificate on disk is newer than the one being served.
#
# DirectAdmin's letsencrypt.sh writes the renewed certificate and does not
# reload Apache. Apache reads certificates once, at start, so after a renewal
# the new file sits on disk while every visitor keeps getting the old one --
# right up to the moment the old one expires. Observed directly on this
# server: disk said 17 Dec, the wire said 20 Nov.
#
# So this compares the two and reloads only when they differ. A reload is
# graceful: worker processes finish their current request, and repeated
# probing during several reloads today showed no dropped connection.
#
# Runs daily from cron. Deliberately does nothing when they already match, so
# it is safe to run as often as you like.
set -u

HOSTS="hamanai.com api.arshanweb.ir"
IP=128.140.54.86
stale=0

for host in $HOSTS; do
    wire=$(echo | openssl s_client -connect "${IP}:443" -servername "$host" 2>/dev/null \
           | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)

    # Whichever user owns it; the cert file is named after the domain.
    disk_file=$(ls /usr/local/directadmin/data/users/*/domains/"${host}".cert 2>/dev/null | head -1)
    [ -n "$disk_file" ] || continue

    disk=$(openssl x509 -in "$disk_file" -noout -enddate 2>/dev/null | cut -d= -f2)
    [ -n "$wire" ] && [ -n "$disk" ] || continue

    if [ "$(date -d "$disk" +%s)" -gt "$(date -d "$wire" +%s)" ]; then
        logger -t cert-reload "New certificate on disk for ${host} (disk ${disk}, served ${wire}) -- reloading httpd"
        stale=1
    fi
done

if [ "$stale" = "1" ]; then
    if apachectl configtest >/dev/null 2>&1; then
        systemctl reload httpd
        logger -t cert-reload "httpd reloaded"
    else
        logger -t cert-reload "REFUSED to reload: apachectl configtest failed"
    fi
fi
