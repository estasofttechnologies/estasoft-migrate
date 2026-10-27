#!/usr/bin/env bash
# Throw-away WordPress site for developing and testing the plugin screens in a browser.
#
#   bin/dev-site.sh up   <name> <port>   create (or start) the site at http://localhost:<port>
#   bin/dev-site.sh down <name>          stop its web server
#   bin/dev-site.sh drop <name>          stop it and delete its files and database
#   bin/dev-site.sh list                 sites and ports
#
# Every site has its own files and database, and the plugin folder is linked into it, so code changes
# show up straight away. Login: admin / admin  (http://localhost:<port>/wp-admin/).
#
# Needs: php, mysql/mariadb running, wp-cli, rsync. Uses an existing WordPress folder as the source
# (WP_SOURCE, default /home/claude/wp-test); without one it downloads WordPress with wp-cli.
# Settings through the environment: WP_SOURCE, SITES_DIR, DB_USER, DB_PASS, DB_HOST, MYSQL_ADMIN.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SITES_DIR="${SITES_DIR:-$HOME/estm-dev-sites}"
WP_SOURCE="${WP_SOURCE:-/home/claude/wp-test}"
DB_USER="${DB_USER:-wp}"
DB_PASS="${DB_PASS:-wp}"
DB_HOST="${DB_HOST:-127.0.0.1}"
MYSQL_ADMIN="${MYSQL_ADMIN:-mysql}"
WP="wp --allow-root"

cmd="${1:-help}"
name="${2:-}"
port="${3:-}"

need_name() { [ -n "$name" ] || { echo "Give the site a name, for example: $0 $cmd shop" >&2; exit 2; }; }
dir()  { echo "$SITES_DIR/$name"; }
dbn()  { echo "estm_dev_$(echo "$name" | tr -c 'A-Za-z0-9\n' '_')"; }

stop_server() {
  local pidfile; pidfile="$(dir)/.server.pid"
  if [ -f "$pidfile" ]; then
    kill "$(cat "$pidfile")" 2>/dev/null || true
    rm -f "$pidfile"
  fi
}

start_server() {
  local d; d="$(dir)"
  stop_server
  # Several workers, because the screens poll while a job runs.
  PHP_CLI_SERVER_WORKERS=6 nohup php -d display_errors=1 -d error_reporting=E_ALL -d memory_limit=512M \
    -S "localhost:$port" -t "$d" > "$d/.server.log" 2>&1 &
  echo $! > "$d/.server.pid"
  for _ in $(seq 1 30); do
    if curl -fsS -o /dev/null "http://localhost:$port/wp-login.php" 2>/dev/null; then return 0; fi
    sleep 0.3
  done
  echo "The web server did not start. See $d/.server.log" >&2
  exit 1
}

case "$cmd" in
  up)
    need_name
    [ -n "$port" ] || { echo "Give a port, for example: $0 up $name 8081" >&2; exit 2; }
    d="$(dir)"; db="$(dbn)"
    if [ ! -f "$d/wp-config.php" ]; then
      mkdir -p "$d"
      if [ -f "$WP_SOURCE/wp-load.php" ]; then
        rsync -a --exclude 'wp-content/uploads/' --exclude 'wp-content/plugins/estasoft-migrate' --exclude 'wp-config.php' "$WP_SOURCE/" "$d/"
      else
        (cd "$d" && $WP core download)
      fi
      $MYSQL_ADMIN -e "CREATE DATABASE IF NOT EXISTS \`$db\` CHARACTER SET utf8mb4;"
      # Give the database user access, whatever host names it was created for.
      for host in $($MYSQL_ADMIN -N -e "SELECT host FROM mysql.user WHERE user='$DB_USER'"); do
        $MYSQL_ADMIN -e "GRANT ALL ON \`$db\`.* TO '$DB_USER'@'$host';"
      done
      (cd "$d" && $WP config create --dbname="$db" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" --skip-check --extra-php <<PHP
define( 'WP_HOME', 'http://localhost:$port' );
define( 'WP_SITEURL', 'http://localhost:$port' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISALLOW_FILE_EDIT', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
// No update checks or other calls to the internet, but other dev sites on this computer stay reachable.
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'WP_ACCESSIBLE_HOSTS', 'localhost' );
PHP
      )
      mkdir -p "$d/wp-content/plugins" "$d/wp-content/uploads"
      ln -sfn "$PLUGIN_DIR" "$d/wp-content/plugins/estasoft-migrate"
      (cd "$d" && $WP core install --url="http://localhost:$port" --title="Dev site $name" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email)
      # Some content, so there is something to move and to mask.
      (cd "$d" && $WP rewrite structure '/%postname%/' >/dev/null 2>&1 || true)
      (cd "$d" && for i in $(seq 1 25); do $WP post create --post_title="Sample post $i" --post_content="Visit http://localhost:$port/about for details. Post $i." --post_status=publish --porcelain >/dev/null; done)
      (cd "$d" && for u in sara.whitfield liam.ortega mei.tanaka; do $WP user create "$u" "$u@customer.example" --role=subscriber --first_name="${u%%.*}" --last_name="${u##*.}" --user_pass=secret123 >/dev/null; done)
      (cd "$d" && $WP comment generate --count=12 >/dev/null 2>&1 || true)
      (cd "$d" && $WP plugin activate estasoft-migrate)
    fi
    start_server
    echo "Ready: http://localhost:$port/wp-admin/admin.php?page=estasoft-migrate   (admin / admin)"
    echo "Files: $d    Database: $db    Server log: $d/.server.log    PHP/WP log: $d/wp-content/debug.log"
    ;;
  down)
    need_name
    stop_server
    echo "Stopped $name."
    ;;
  drop)
    need_name
    stop_server
    $MYSQL_ADMIN -e "DROP DATABASE IF EXISTS \`$(dbn)\`;" || true
    rm -rf "$(dir)"
    echo "Deleted $name."
    ;;
  list)
    for d in "$SITES_DIR"/*/; do
      [ -f "$d/wp-config.php" ] || continue
      n="$(basename "$d")"
      u="$(grep -o "WP_HOME', '[^']*" "$d/wp-config.php" | sed "s/WP_HOME', '//")"
      state="stopped"; [ -f "$d/.server.pid" ] && kill -0 "$(cat "$d/.server.pid")" 2>/dev/null && state="running"
      echo "$n  $u  $state"
    done
    ;;
  *)
    sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'
    ;;
esac
