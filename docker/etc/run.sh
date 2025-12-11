#!/usr/bin/env bash

echo "`/sbin/ip route|awk '/default/ { print $3 }'`\tdocker.host.ip" | tee -a /etc/hosts > /dev/null

supervisord -c /etc/supervisor/conf.d/supervisord.conf