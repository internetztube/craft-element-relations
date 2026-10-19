#!/usr/bin/env bash
# Craft 5 dev environment for this plugin. Idempotent; safe to re-run.
#   dev/dev.sh          setup (if needed) + start server in background
#   dev/dev.sh serve    start server in background (http://localhost:8080/admin, admin / password)
#   dev/dev.sh stop     stop server
#   dev/dev.sh e2e      start the server and run dev/e2e.php (control panel over HTTP: login, edit page, index + replace endpoints)
#   dev/dev.sh queue    run queued jobs (plugin refreshes its cache via queue)
#   dev/dev.sh seed     create Pages section + "Page A1..A12" -> "Page B"
#   dev/dev.sh smoke    run dev/smoke.php assertions against the seeded project
#   dev/dev.sh reset    drop DB and reinstall from scratch
#   dev/dev.sh ci       scaffold + install + smoke against an already running DB (what GitHub Actions runs)
# Env: PROJECT_DIR (default ../craft-element-relations-dev), PORT (8080), DB_DRIVER (pgsql|mysql, default pgsql),
#      DB_NAME (default: project dir name), DB_SERVER (127.0.0.1), DB_PORT, DB_USER, DB_PASSWORD (local defaults: no password).
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
# Override PROJECT_DIR/PORT to run several isolated projects side by side (e.g. one per agent).
PROJECT_DIR="${PROJECT_DIR:-${PLUGIN_DIR}-dev}"
PORT="${PORT:-8080}"
DB_DRIVER="${DB_DRIVER:-pgsql}"
DB="${DB_NAME:-$(basename "$PROJECT_DIR" | tr '-' '_')}"
DB_SERVER="${DB_SERVER:-127.0.0.1}"
DB_PORT="${DB_PORT:-$([ "$DB_DRIVER" = mysql ] && echo 3306 || echo 5432)}"
DB_USER="${DB_USER:-$([ "$DB_DRIVER" = mysql ] && echo root || echo "$USER")}"
DB_PASSWORD="${DB_PASSWORD:-}"
PIDFILE="$PROJECT_DIR/storage/serve.pid"
export PATH="/opt/homebrew/opt/postgresql@14/bin:/opt/homebrew/opt/mysql@8.0/bin:$PATH"
MYSQL="mysql -u$DB_USER -h$DB_SERVER -P$DB_PORT --protocol=tcp${DB_PASSWORD:+ -p$DB_PASSWORD}"

db_up() {
  if [ "$DB_DRIVER" = "mysql" ]; then
    mysqladmin -uroot -h127.0.0.1 --protocol=tcp ping >/dev/null 2>&1 || brew services start mysql@8.0 >/dev/null
    for _ in $(seq 1 30); do mysqladmin -uroot -h127.0.0.1 --protocol=tcp ping >/dev/null 2>&1 && break; sleep 1; done
    $MYSQL -e "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
  else
    pg_isready -q || brew services start postgresql@14 >/dev/null
    for _ in $(seq 1 20); do pg_isready -q && break; sleep 1; done
    psql -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='$DB'" | grep -q 1 || createdb "$DB"
  fi
}

db_drop() {
  if [ "$DB_DRIVER" = "mysql" ]; then $MYSQL -e "DROP DATABASE IF EXISTS \`$DB\`"; else dropdb --if-exists "$DB"; fi
}

scaffold() {
  [ -d "$PROJECT_DIR" ] && return
  composer create-project craftcms/craft "$PROJECT_DIR" --no-interaction --no-scripts
  cd "$PROJECT_DIR"
  composer config repositories.element-relations "{\"type\":\"path\",\"url\":\"$PLUGIN_DIR\",\"options\":{\"symlink\":true}}"
  composer config minimum-stability dev
  composer config prefer-stable true
  composer require internetztube/craft-element-relations:@dev --no-interaction
  cat > .env <<ENV
CRAFT_APP_ID=
CRAFT_ENVIRONMENT=dev
CRAFT_SECURITY_KEY=
CRAFT_DEV_MODE=true
CRAFT_ALLOW_ADMIN_CHANGES=true
CRAFT_DISALLOW_ROBOTS=true
CRAFT_DB_DRIVER=$DB_DRIVER
CRAFT_DB_SERVER=$DB_SERVER
CRAFT_DB_PORT=$DB_PORT
CRAFT_DB_DATABASE=$DB
CRAFT_DB_USER=$DB_USER
CRAFT_DB_PASSWORD=$DB_PASSWORD
CRAFT_DB_SCHEMA=public
CRAFT_DB_TABLE_PREFIX=
PRIMARY_SITE_URL=http://localhost:$PORT
ENV
  php craft setup/security-key
  php craft setup/app-id
}

install() {
  cd "$PROJECT_DIR"
  php craft install/check >/dev/null 2>&1 && return
  php craft install --interactive=0 --email=admin@example.com --username=admin --password=password \
    --siteName="Element Relations Dev" --siteUrl='$PRIMARY_SITE_URL' --language=en-US
}

install_plugins() {
  cd "$PROJECT_DIR"
  # every third-party plugin an extractor supports (dev/plugins.txt); no-op when already required
  xargs composer require --no-interaction --quiet < "$PLUGIN_DIR/dev/plugins.txt"
  for handle in $(php craft plugin/list | awk 'NF>5 && $(NF-1)=="No"{print $(NF-4)}'); do php craft plugin/install "$handle"; done
}
# seed twice: schema created in the first process is only fully usable in a fresh one (see ensureEntry in seed.php)
seed()  { install_plugins && php craft migrate/all --no-backup --interactive=0 && php "$PLUGIN_DIR/dev/seed.php" "$PROJECT_DIR" && php "$PLUGIN_DIR/dev/seed.php" "$PROJECT_DIR" && php craft queue/release all && php craft element-relations/resave-relations && php craft queue/run; }
# the tests switch the plugin edition in console processes; write the project config YAML back afterwards so the
# control panel does not report pending changes
syncpc() { (cd "$PROJECT_DIR" && php craft project-config/write >/dev/null 2>&1) || true; }
smoke() { seed && php "$PLUGIN_DIR/dev/smoke.php" "$PROJECT_DIR"; local rc=$?; syncpc; return $rc; }
e2e()   { serve && php "$PLUGIN_DIR/dev/e2e.php" "$PROJECT_DIR" "http://localhost:$PORT"; local rc=$?; stop; syncpc; return $rc; }

stop() { [ -f "$PIDFILE" ] && kill "$(cat "$PIDFILE")" 2>/dev/null || true; rm -f "$PIDFILE"; }

serve() {
  stop
  cd "$PROJECT_DIR"
  nohup php -S localhost:$PORT -t "$PROJECT_DIR/web" "$PROJECT_DIR/vendor/craftcms/cms/bootstrap/router.php" > storage/serve.log 2>&1 &
  echo $! > "$PIDFILE"
  for _ in $(seq 1 10); do curl -sf -o /dev/null "http://localhost:$PORT/admin/login" && break; sleep 1; done
  echo "Craft running at http://localhost:$PORT/admin  (admin / password)  log: $PROJECT_DIR/storage/serve.log"
}

case "${1:-up}" in
  up)    db_up; scaffold; install; seed; serve ;;
  serve) serve ;;
  stop)  stop ;;
  queue) cd "$PROJECT_DIR" && php craft queue/run ;;
  seed)  seed ;;
  smoke) smoke ;;
  reset) stop; db_drop; db_up; install; seed; serve ;;
  e2e)   e2e ;;
  ci)    scaffold; install; smoke; e2e ;;
  *)     sed -n "2,13p" "$0"; exit 1 ;;
esac
