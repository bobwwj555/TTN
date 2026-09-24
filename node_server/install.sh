#!/bin/bash
# TTN Node Telemetry Agent — installer
# Installs ttn-logger.php + ttn-status.php on a TTN node server and wires up
# the cron job that feeds the portal's Last Heard / node-status data.
#
# PREREQUISITE: Allmon3 (or equivalent) must already be installed and
# configured on this box, with a working allmon3.ini (or allmon.ini) listing
# AMI host/port/user/pass per node. This installer does NOT set that up --
# it only reads it. If you don't have AMI access to your node(s) configured
# yet, set that up first (Allmon3: https://github.com/AllStarLink/Allmon3).
set -e

WEBROOT="${TTN_WEBROOT:-/var/www/html}"
CONF_FILE="/etc/ttn-logger.conf"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ "$EUID" -ne 0 ]; then
    echo "Run this as root (sudo ./install.sh)." >&2
    exit 1
fi

echo "== TTN Node Telemetry Agent installer =="

# 1. Confirm an AMI credentials file exists -- we don't create this, Allmon3
#    (or equivalent) owns it.
INI_PATHS=(
    /etc/allmon3/allmon3.ini
    /usr/local/etc/allmon3/allmon3.ini
    /var/www/html/allmon3.ini
    /etc/asterisk/allmon.ini
    /var/www/html/allmon.ini
    /usr/local/etc/allmon.ini
)
FOUND_INI=""
for p in "${INI_PATHS[@]}"; do
    if [ -r "$p" ]; then FOUND_INI="$p"; break; fi
done
if [ -z "$FOUND_INI" ]; then
    echo "ERROR: no Allmon3/allmon-style ini file found with AMI credentials." >&2
    echo "Checked: ${INI_PATHS[*]}" >&2
    echo "Set up Allmon3 (or an equivalent ini with [nodenum] AMI host/user/pass" >&2
    echo "sections) first, then re-run this installer." >&2
    exit 1
fi
echo "Found AMI credentials at: $FOUND_INI"

# 2. Install the PHP files
echo "Installing ttn-logger.php and ttn-status.php to $WEBROOT ..."
install -o www-data -g www-data -m 644 "$SCRIPT_DIR/ttn-logger.php" "$WEBROOT/ttn-logger.php"
install -o www-data -g www-data -m 644 "$SCRIPT_DIR/ttn-status.php" "$WEBROOT/ttn-status.php"

# 3. Set up /etc/ttn-logger.conf from the template if not already present.
#    Never overwrites an existing config.
if [ -f "$CONF_FILE" ]; then
    echo "$CONF_FILE already exists -- leaving it alone."
else
    cp "$SCRIPT_DIR/ttn-logger.conf" "$CONF_FILE"
    chmod 600 "$CONF_FILE"
    SECRET="$(openssl rand -hex 32)"
    sed -i "s/^portal_secret = .*/portal_secret = $SECRET/" "$CONF_FILE"
    echo ""
    echo "Created $CONF_FILE with a freshly generated secret:"
    echo "  $SECRET"
    echo "Give this value to the TTN portal admin -- it must match the portal's"
    echo "'telemetry_secret' site setting (Admin -> Settings) for this node's"
    echo "reports to be accepted."
    echo "Also confirm 'portal_url' in $CONF_FILE points at the right portal"
    echo "endpoint for your deployment."
    echo ""
fi

# 4. Cron -- runs as www-data, every 5 minutes (matches the original TTN
#    deployment; removes any prior ttn-logger.php entry first so re-running
#    this installer is safe/idempotent).
CRON_LINE="*/5 * * * * /usr/bin/php $WEBROOT/ttn-logger.php >> /var/log/ttn-telemetry.log 2>&1"
( crontab -u www-data -l 2>/dev/null | grep -v 'ttn-logger.php' ; echo "$CRON_LINE" ) | crontab -u www-data -
touch /var/log/ttn-telemetry.log
chown www-data:www-data /var/log/ttn-telemetry.log
echo "Installed www-data cron job: $CRON_LINE"

echo ""
echo "== Done =="
echo "Test manually first:  sudo -u www-data php $WEBROOT/ttn-logger.php"
echo "Then check:           tail -f /var/log/ttn-telemetry.log"
echo "And confirm the portal's Last Heard / node status page shows this node."
