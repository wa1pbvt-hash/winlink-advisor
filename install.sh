#!/bin/bash
set -euo pipefail

# Winlink Propagation Advisor installer
# Installs the Advisor engine and web interface on a DigiPi.
# Does not install, configure, start, or modify PAT/Mercury/VARA/ARDOP.

INSTALL_DIR="/home/pi/winlink-advisor"
WEB_DIR="/var/www/html"
BACKUP_DIR="$INSTALL_DIR/backups"
CONFIG_FILE="/home/pi/localize.env"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "========================================"
echo " Winlink Propagation Advisor Installer"
echo "========================================"
echo

if [[ "${EUID}" -ne 0 ]]; then
    echo "ERROR: Run this installer with sudo."
    echo "Example: sudo ./install.sh"
    exit 1
fi

if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "ERROR: $CONFIG_FILE was not found."
    exit 1
fi

if ! command -v python3 >/dev/null 2>&1; then
    echo "ERROR: python3 was not found."
    exit 1
fi

if ! command -v php >/dev/null 2>&1; then
    echo "ERROR: php was not found."
    exit 1
fi

for required_file in \
    "$SCRIPT_DIR/advisor.py" \
    "$SCRIPT_DIR/web/winlink-advisor.php" \
    "$SCRIPT_DIR/docs/winlink-advisor-help.txt"
do
    if [[ ! -f "$required_file" ]]; then
        echo "ERROR: Required package file missing:"
        echo "  $required_file"
        exit 1
    fi
done

NEWCALL=""
NEWGRID=""
WINLINK_KEY=""

while IFS='=' read -r key value; do
    key="${key#"${key%%[![:space:]]*}"}"
    key="${key%"${key##*[![:space:]]}"}"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"

    case "$key" in
        NEWCALL) NEWCALL="$value" ;;
        NEWGRID) NEWGRID="$value" ;;
        WINLINK_KEY) WINLINK_KEY="$value" ;;
    esac
done < "$CONFIG_FILE"

if [[ -z "$NEWCALL" || -z "$NEWGRID" ]]; then
    echo "ERROR: localize.env must contain NEWCALL and NEWGRID."
    exit 1
fi

echo "Station configuration:"
echo "  Callsign: $NEWCALL"
echo "  Grid:     $NEWGRID"

if [[ -n "$WINLINK_KEY" ]]; then
    echo "  Key:      present"
else
    echo "  Key:      not present"
    echo "  WARNING: Winlink gateway status will not work until WINLINK_KEY is configured."
fi

echo
echo "Checking package..."

python3 -m py_compile "$SCRIPT_DIR/advisor.py"

echo "Package checks passed."

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"

if [[ -f "$INSTALL_DIR/advisor.py" || \
      -f "$WEB_DIR/winlink-advisor.php" || \
      -f "$WEB_DIR/winlink-advisor-help.txt" ]]; then

    mkdir -p "$BACKUP_DIR/$TIMESTAMP"

    echo
    echo "Existing Advisor installation found."
    echo "Creating backup: $BACKUP_DIR/$TIMESTAMP"

    if [[ -f "$INSTALL_DIR/advisor.py" ]]; then
        cp -a "$INSTALL_DIR/advisor.py" "$BACKUP_DIR/$TIMESTAMP/"
    fi

    if [[ -f "$WEB_DIR/winlink-advisor.php" ]]; then
        cp -a "$WEB_DIR/winlink-advisor.php" "$BACKUP_DIR/$TIMESTAMP/"
    fi

    if [[ -f "$WEB_DIR/winlink-advisor-help.txt" ]]; then
        cp -a "$WEB_DIR/winlink-advisor-help.txt" "$BACKUP_DIR/$TIMESTAMP/"
    fi
fi

echo
echo "Installing Advisor..."

mkdir -p "$INSTALL_DIR"
mkdir -p "$WEB_DIR"

install -m 0755 \
    "$SCRIPT_DIR/advisor.py" \
    "$INSTALL_DIR/advisor.py"

install -m 0644 \
    "$SCRIPT_DIR/web/winlink-advisor.php" \
    "$WEB_DIR/winlink-advisor.php"

install -m 0644 \
    "$SCRIPT_DIR/docs/winlink-advisor-help.txt" \
    "$WEB_DIR/winlink-advisor-help.txt"

chown pi:pi "$INSTALL_DIR/advisor.py"
chown root:root "$WEB_DIR/winlink-advisor.php"
chown root:root "$WEB_DIR/winlink-advisor-help.txt"

echo
echo "Running installed-copy self-test..."

PYTHONPATH="$INSTALL_DIR" python3 - <<'PY'
import advisor

tests = [
    ("West", 40, -120),
    ("Central", 40, -100),
    ("East", 40, -75),
    ("Europe / Atlantic", 50, 0),
    ("Europe / Africa", 45, 20),
]

for expected, lat, lon in tests:
    actual = advisor.classify_region(lat, lon)
    if actual != expected:
        print(f"FAIL: {lat}, {lon} -> {actual} (expected {expected})")
        raise SystemExit(1)
    print(f"OK: {lat}, {lon} -> {actual}")

print("Regional classification test passed.")
PY

echo
echo "Checking PHP syntax..."

php -l "$WEB_DIR/winlink-advisor.php"

echo
echo "Checking installed files..."

[[ -x "$INSTALL_DIR/advisor.py" ]]
[[ -f "$WEB_DIR/winlink-advisor.php" ]]
[[ -f "$WEB_DIR/winlink-advisor-help.txt" ]]

echo "Installed files verified."

echo
echo "========================================"
echo " Installation completed successfully."
echo "========================================"
echo
echo "Advisor: $INSTALL_DIR/advisor.py"
echo "Web UI:  $WEB_DIR/winlink-advisor.php"
echo "Help:    $WEB_DIR/winlink-advisor-help.txt"
echo

if [[ -x /usr/local/bin/digipi-connect-pat.sh ]]; then
    echo "PAT integration: controller detected."
else
    echo "PAT integration: not installed (optional)."
fi

echo
echo "PAT/Mercury/VARA/ARDOP were not modified."
echo
