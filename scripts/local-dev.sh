#!/usr/bin/env bash
#
# Local, Docker-free development runner for GLPI.
#
# Runs MariaDB as your own user (data lives in .local-dev/) and serves GLPI
# with the PHP built-in server. Much lighter than the docker-compose stack.
#
# Usage: scripts/local-dev.sh <command>
# Commands:
#   setup       Install system packages (needs sudo, Arch only)
#   deps        Install PHP/JS dependencies (composer + npm via bin/console)
#   db-init     Create the database datadir (first time only)
#   db-start    Start MariaDB in the background
#   db-stop     Stop MariaDB
#   db-install  Create users/databases and install GLPI's schema
#   start       Start MariaDB (if needed) and serve GLPI on http://127.0.0.1:8081
#   stop        Stop everything
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE="$ROOT/.local-dev"
DB_DIR="$STATE/mysql"
SOCKET="$STATE/mysql.sock"
PID_FILE="$STATE/mariadb.pid"
LOG_FILE="$STATE/mariadb.log"

DB_PORT="${LOCAL_DEV_DB_PORT:-3306}"
WEB_HOST="${LOCAL_DEV_WEB_HOST:-127.0.0.1}"
WEB_PORT="${LOCAL_DEV_WEB_PORT:-8081}"
PHP_MEM="${LOCAL_DEV_PHP_MEM:-512M}"

PACKAGES=(php php-gd php-intl mariadb composer)

info() { printf '\033[32m[local-dev]\033[0m %s\n' "$1"; }
die()  { printf '\033[31m[local-dev]\033[0m %s\n' "$1" >&2; exit 1; }

need_setup() {
	command -v php >/dev/null 2>&1 || return 0
	command -v mariadbd >/dev/null 2>&1 || return 0
	return 1
}

db_ping() {
	mariadb-admin --socket="$SOCKET" --user=root ping >/dev/null 2>&1
}

wait_for_db() {
	for _ in $(seq 1 30); do
		db_ping && return 0
		sleep 1
	done
	die "MariaDB did not come up, check $LOG_FILE"
}

cmd_setup() {
	sudo pacman -S --needed "${PACKAGES[@]}"
	info "Packages installed. Next: $0 db-init && $0 deps && $0 db-install"
}

cmd_deps() {
	cd "$ROOT"
	php bin/console dependencies install
	info "Dependencies installed."
}

cmd_db_init() {
	if [ -d "$DB_DIR/mysql" ]; then
		info "Datadir already exists at $DB_DIR, nothing to do."
		return
	fi
	mkdir -p "$DB_DIR"
	mariadb-install-db \
		--datadir="$DB_DIR" \
		--auth-root-authentication-method=normal >/dev/null
	info "Database initialized at $DB_DIR"
}

cmd_db_start() {
	db_ping && { info "MariaDB already running."; return; }
	[ -d "$DB_DIR/mysql" ] || cmd_db_init
	mariadbd \
		--no-defaults \
		--datadir="$DB_DIR" \
		--socket="$SOCKET" \
		--port="$DB_PORT" \
		--bind-address=127.0.0.1 \
		--skip-name-resolve \
		>>"$LOG_FILE" 2>&1 &
	echo $! >"$PID_FILE"
	wait_for_db
	info "MariaDB running on 127.0.0.1:$DB_PORT (pid $(cat "$PID_FILE"))."
}

cmd_db_stop() {
	if [ -f "$PID_FILE" ]; then
		kill "$(cat "$PID_FILE")" 2>/dev/null || true
		rm -f "$PID_FILE" "$SOCKET"
	fi
	info "MariaDB stopped."
}

cmd_db_install() {
	cmd_db_start
	mariadb --socket="$SOCKET" --user=root <<SQL
CREATE DATABASE IF NOT EXISTS glpi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS glpi_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'glpi'@'127.0.0.1' IDENTIFIED BY 'glpi';
GRANT ALL PRIVILEGES ON glpi.* TO 'glpi'@'127.0.0.1';
GRANT ALL PRIVILEGES ON glpi_test.* TO 'glpi'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
	cd "$ROOT"
	php bin/console database:install \
		-r -f \
		--db-host=127.0.0.1 \
		--db-port="$DB_PORT" \
		--db-name=glpi \
		--db-user=glpi \
		--db-password=glpi \
		--no-interaction \
		--no-telemetry
	info "GLPI database installed. Default login: glpi / glpi"
}

cmd_start() {
	if need_setup; then
		die "PHP/MariaDB not found. Run '$0 setup' first."
	fi
	cmd_db_start
	cd "$ROOT/public"
	info "Serving GLPI at http://$WEB_HOST:$WEB_PORT"
	exec php -d memory_limit="$PHP_MEM" -S "$WEB_HOST:$WEB_PORT"
}

cmd_stop() {
	cmd_db_stop
}

case "${1:-help}" in
	setup)      cmd_setup ;;
	deps)       cmd_deps ;;
	db-init)    cmd_db_init ;;
	db-start)   cmd_db_start ;;
	db-stop)    cmd_db_stop ;;
	db-install) cmd_db_install ;;
	start)      cmd_start ;;
	stop)       cmd_stop ;;
	help|*)     sed -n '3,17p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' ;;
esac
