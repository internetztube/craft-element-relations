#!/usr/bin/env bash
# Craft 6 dev environment for this plugin (a Laravel app with craftcms/cms). Idempotent; safe to re-run.
#   dev/dev.sh          setup (if needed) + start server in background
#   dev/dev.sh serve    start server in background (http://localhost:8080/admin, admin / password)
#   dev/dev.sh stop     stop server
#   dev/dev.sh e2e      start the server and run dev/e2e.php (control panel over HTTP: login, edit page, index + replace endpoints)
#   dev/dev.sh ui       start the server and run dev/ui.mts (the control panel in headless Chrome: lists, wizard, delete dialog, editor)
#   dev/dev.sh screenshots  documentation screenshots of the demo site into docs/screenshots (dev/screenshots.mts)
#   dev/dev.sh queue    run queued jobs until the queue is empty (plugin refreshes its cache via queue)
#   dev/dev.sh seed     test fixtures (Pages section, "Page A1..A12" -> "Page B", one entry per integration) + demo site
#   dev/dev.sh smoke    run dev/smoke.php assertions against the seeded project
#   dev/dev.sh reset    drop DB and reinstall from scratch
#   dev/dev.sh ci       scaffold + install + smoke + e2e + ui against an already running DB (what GitHub Actions runs)
#   dev/dev.sh coverage ci with line coverage of src/ (pcov), then the report: total, files below the bar, HTML
#   dev/dev.sh typecheck  tsc over the TypeScript dev scripts (dev/*.mts; installs typescript into dev/node_modules once)
# Env: PROJECT_DIR (default ../craft-element-relations-dev6), PORT (8090), DB_DRIVER (pgsql|mysql, default pgsql),
#      DB_NAME (default: project dir name), DB_SERVER (127.0.0.1), DB_PORT, DB_USER, DB_PASSWORD (local defaults: no password).
#      YII2_ADAPTER=1 installs Craft's Yii2 adapter into the project (categories, tags, global sets); it stays installed.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
# Override PROJECT_DIR/PORT to run several isolated projects side by side (e.g. one per agent).
# own defaults: 4.x keeps its Craft 5 project in ${PLUGIN_DIR}-dev on port 8080
PROJECT_DIR="${PROJECT_DIR:-${PLUGIN_DIR}-dev6}"
PORT="${PORT:-8090}"
DB_DRIVER="${DB_DRIVER:-pgsql}"
DB="${DB_NAME:-$(basename "$PROJECT_DIR" | tr '-' '_')}"
DB_SERVER="${DB_SERVER:-127.0.0.1}"
DB_PORT="${DB_PORT:-$([ "$DB_DRIVER" = mysql ] && echo 3306 || echo 5432)}"
DB_USER="${DB_USER:-$([ "$DB_DRIVER" = mysql ] && echo root || echo "$USER")}"
DB_PASSWORD="${DB_PASSWORD:-}"
PIDFILE="$PROJECT_DIR/storage/serve.pid"
# Craft 6 needs PHP 8.5 (Homebrew's `php`; the default `php` of a Mac may be older)
export PATH="/opt/homebrew/opt/php/bin:/opt/homebrew/opt/postgresql@14/bin:/opt/homebrew/opt/mysql@8.0/bin:$PATH"
MYSQL="mysql -u$DB_USER -h$DB_SERVER -P$DB_PORT --protocol=tcp${DB_PASSWORD:+ -p$DB_PASSWORD}"
# COVERAGE=1: every PHP process the suites run (the console scripts, the dev server, the queue) prepends
# dev/coverage.php (pcov, line coverage of src/ into storage/coverage); dev/coverage-report.php merges and prints it
# (no opcache: the built-in server uses it, and its optimizer drops lines such as `continue;` from what pcov sees)
PHP=(php)
ROUTER="$PROJECT_DIR/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
if [ "${COVERAGE:-0}" = 1 ]; then export COVERAGE_PROJECT_DIR="$PROJECT_DIR"; PHP=(php -d pcov.enabled=1 -d opcache.enable=0 -d "pcov.directory=$PLUGIN_DIR" -d "auto_prepend_file=$PLUGIN_DIR/dev/coverage.php"); ROUTER="$PLUGIN_DIR/dev/coverage-router.php"; fi

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
  composer create-project "craftcms/craft:^6.0.0-alpha.1" "$PROJECT_DIR" --no-interaction --no-scripts
  cd "$PROJECT_DIR"
  [ -f composer.json.default ] && mv composer.json.default composer.json
  composer config repositories.element-relations "{\"type\":\"path\",\"url\":\"$PLUGIN_DIR\",\"options\":{\"symlink\":true}}"
  composer config minimum-stability dev
  composer config prefer-stable true
  composer config allow-plugins.yiisoft/yii2-composer true
  composer require internetztube/craft-element-relations:@dev -W --no-interaction
  composer require --dev phpunit/php-code-coverage --no-interaction
  cp .env.example .env
  local driver; driver=$([ "$DB_DRIVER" = mysql ] && echo mysql || echo pgsql)
  sed -i.bak -e "s|^DB_CONNECTION=.*|DB_CONNECTION=$driver|" -e "s|^#* *DB_HOST=.*|DB_HOST=$DB_SERVER|" -e "s|^#* *DB_PORT=.*|DB_PORT=$DB_PORT|" \
    -e "s|^#* *DB_DATABASE=.*|DB_DATABASE=$DB|" -e "s|^#* *DB_USERNAME=.*|DB_USERNAME=$DB_USER|" -e "s|^#* *DB_PASSWORD=.*|DB_PASSWORD=$DB_PASSWORD|" \
    -e "s|^APP_URL=.*|APP_URL=http://localhost:$PORT|" -e "s|^QUEUE_CONNECTION=.*|QUEUE_CONNECTION=database|" .env && rm -f .env.bak
  grep -q '^DB_HOST=' .env || printf 'DB_HOST=%s\nDB_PORT=%s\nDB_DATABASE=%s\nDB_USERNAME=%s\nDB_PASSWORD=%s\n' "$DB_SERVER" "$DB_PORT" "$DB" "$DB_USER" "$DB_PASSWORD" >> .env
  php artisan key:generate --ansi
  php artisan storage:link --quiet # the test volume lives on the public disk
  composer dump-autoload --quiet
}

install() {
  cd "$PROJECT_DIR"
  composer show phpunit/php-code-coverage >/dev/null 2>&1 || composer require --dev phpunit/php-code-coverage --no-interaction --quiet
  if ! php artisan craft:install:check >/dev/null 2>&1; then
    php artisan craft:install --no-interaction --email=admin@example.com --username=admin --password=password \
      --siteName="Element Relations Dev" --siteUrl="http://localhost:$PORT" --language=en-US --timezone=UTC
  fi
  php artisan craft:plugin:install element-relations --no-interaction >/dev/null 2>&1 || true
}

# third-party plugins an extractor supports and that exist for Craft 6 (dev/plugins.txt; none yet)
install_plugins() {
  cd "$PROJECT_DIR"
  # YII2_ADAPTER=1: Craft's Yii2 adapter, the version of the installed Craft (categories, tags, global sets; dev/seed/adapter.php)
  if [ "${YII2_ADAPTER:-0}" = 1 ] && ! composer show craftcms/yii2-adapter >/dev/null 2>&1; then
    # -W: it needs older Symfony packages (symfony/mailer 7) than a fresh Craft 6 project locks
    composer require "craftcms/yii2-adapter:$(composer show craftcms/cms | sed -n 's/^versions : \* //p')" -W --no-interaction --quiet || return 1
  fi
  grep -v '^#' "$PLUGIN_DIR/dev/plugins.txt" | grep -q . || return 0
  grep -v '^#' "$PLUGIN_DIR/dev/plugins.txt" | xargs composer require --no-interaction --quiet
}
# seed twice: schema created in the first process is only fully usable in a fresh one (see ensureEntry in seed.php)
# then the demo site next to the fixtures (dev/demo.php: schema and content in separate processes for the same reason)
seed()  { install_plugins && php artisan craft:migrate:all --no-interaction >/dev/null && php "$PLUGIN_DIR/dev/seed.php" "$PROJECT_DIR" && php "$PLUGIN_DIR/dev/seed.php" "$PROJECT_DIR" && { [ ! -f "$PLUGIN_DIR/dev/demo.php" ] || { php "$PLUGIN_DIR/dev/demo.php" "$PROJECT_DIR" schema && php "$PLUGIN_DIR/dev/demo.php" "$PROJECT_DIR" content; }; } && php artisan element-relations:resave-relations && queue; }
# runs the queued jobs until none is left (Laravel's database queue)
queue() { (cd "$PROJECT_DIR" && "${PHP[@]}" artisan queue:work --stop-when-empty --tries=1 --quiet); }
# the tests switch the plugin edition in console processes; write the project config YAML back afterwards so the
# control panel does not report pending changes
syncpc() { (cd "$PROJECT_DIR" && php artisan craft:project-config:write >/dev/null 2>&1) || true; }
smoke() { seed && "${PHP[@]}" "$PLUGIN_DIR/dev/smoke.php" "$PROJECT_DIR"; local rc=$?; syncpc; return $rc; }
e2e()   { serve && php "$PLUGIN_DIR/dev/e2e.php" "$PROJECT_DIR" "http://localhost:$PORT"; local rc=$?; stop; syncpc; return $rc; }
# the control panel in a real browser (headless Chrome), dev/ui.mts: what a person sees and does
ui()    { serve && queue && node "$PLUGIN_DIR/dev/ui.mts" "$PROJECT_DIR" "http://localhost:$PORT"; local rc=$?; stop; syncpc; return $rc; }
# documentation screenshots of the demo site into docs/screenshots (queue first: pending image transforms show a job
# indicator in the control panel)
screenshots() { serve && queue && (cd "$PLUGIN_DIR" && node dev/screenshots.mts "$PROJECT_DIR" "http://localhost:$PORT" docs/screenshots); }

# the server's workers are children of the process in the pid file
stop() { [ -f "$PIDFILE" ] && { pkill -P "$(cat "$PIDFILE")" 2>/dev/null; kill "$(cat "$PIDFILE")" 2>/dev/null; } || true; rm -f "$PIDFILE"; }

serve() {
  stop
  cd "$PROJECT_DIR"
  # several workers: the control panel loads its pages' data in parallel requests (one worker hangs on them)
  (cd "$PROJECT_DIR/public" && PHP_CLI_SERVER_WORKERS=6 nohup "${PHP[@]}" -S localhost:$PORT "$ROUTER" > "$PROJECT_DIR/storage/serve.log" 2>&1 & echo $! > "$PIDFILE")
  for _ in $(seq 1 20); do curl -sf -o /dev/null "http://localhost:$PORT/admin/login" && break; sleep 1; done
  echo "Craft running at http://localhost:$PORT/admin  (admin / password)  log: $PROJECT_DIR/storage/serve.log"
}

# ci logs to storage/ci.log; dev/tests-md.php turns the "ok" lines into dev/TESTS.md, the list of what is tested
# a suite still waiting in dev/port/ (being ported from Craft 5) is skipped, with a note in the log
unported() { local missing=""; [ -f "$PLUGIN_DIR/dev/e2e.php" ] || missing="$missing e2e"; [ -f "$PLUGIN_DIR/dev/ui.mts" ] || missing="$missing ui"; echo "$missing"; }
suite() { if [ -f "$PLUGIN_DIR/dev/$2" ]; then "$1"; else echo "Skipped $1: dev/$2 is not ported to Craft 6 yet"; fi; }
ci() { scaffold; install; { smoke && suite e2e e2e.php && suite ui ui.mts; } 2>&1 | tee "$PROJECT_DIR/storage/ci.log"; local rc=${PIPESTATUS[0]}; php "$PLUGIN_DIR/dev/tests-md.php" "$PROJECT_DIR/storage/ci.log" > "$PLUGIN_DIR/dev/TESTS.md"; return $rc; }
# the dev scripts are TypeScript, run by Node 24 as they are (type stripping); this checks their types
typecheck() { (cd "$PLUGIN_DIR/dev" && { [ -x node_modules/.bin/tsc ] || npm install --no-package-lock --no-audit --no-fund; } && node_modules/.bin/tsc -p .); }
coverage() {
  [ "${COVERAGE:-0}" = 1 ] || COVERAGE=1 exec "$0" coverage
  rm -rf "${PROJECT_DIR:?}/storage/coverage"
  local rc=0
  ci || rc=$? # set -e: report the coverage of a failing run too
  # the bar needs every suite; while one is still being ported, the coverage is only reported
  if [ -n "$(unported)" ]; then echo "Coverage not enforced: not ported yet:$(unported)"; export COVERAGE_MIN=0; fi
  php "$PLUGIN_DIR/dev/coverage-report.php" "$PROJECT_DIR" && return $rc
}

case "${1:-up}" in
  up)    db_up; scaffold; install; seed; serve ;;
  serve) serve ;;
  stop)  stop ;;
  queue) queue ;;
  seed)  seed ;;
  smoke) smoke ;;
  reset) stop; db_drop; db_up; install; seed; serve ;;
  e2e)   e2e ;;
  ui)    ui ;;
  screenshots) screenshots ;;
  ci)    ci ;;
  coverage) coverage ;;
  typecheck) typecheck ;;
  *)     sed -n "2,15p" "$0"; exit 1 ;;
esac
