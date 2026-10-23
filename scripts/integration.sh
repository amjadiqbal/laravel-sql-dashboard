#!/usr/bin/env bash
# Runs tests/Integration against real servers in throwaway Docker containers: MySQL 8.4, MariaDB 11 and
# PostgreSQL 16. Each one is seeded with a read-only user, a user that can write and an admin login, then the
# security suite runs (refused statements, write-capable connections, the database refusing writes itself,
# timeouts, row limits, bound variables).
#   scripts/integration.sh                 all three
#   SERVERS=postgres scripts/integration.sh one
#   KEEP=1 scripts/integration.sh          leave the containers running afterwards
set -uo pipefail
cd "$(dirname "$0")/.."

SERVERS=${SERVERS:-"mysql mariadb postgres"}
FAILED=0; RESULTS=()

wait_for() { # driver port
  for _ in $(seq 1 90); do
    if [ "$1" = pgsql ]; then
      php -r 'try { new PDO("pgsql:host=127.0.0.1;port='"$2"';dbname=postgres", "postgres", "root"); exit(0); } catch (Throwable) { exit(1); }' && return 0
    else
      php -r 'try { new PDO("mysql:host=127.0.0.1;port='"$2"'", "root", "root"); exit(0); } catch (Throwable) { exit(1); }' && return 0
    fi
    sleep 2
  done
  return 1
}

for server in $SERVERS; do
  case $server in
    mysql)    name=sqld-it-mysql84;   image=mysql:8.4;   port=33284; driver=mysql;   args=(-e MYSQL_ROOT_PASSWORD=root) ;;
    mariadb)  name=sqld-it-mariadb11; image=mariadb:11;  port=33211; driver=mariadb; args=(-e MARIADB_ROOT_PASSWORD=root) ;;
    postgres) name=sqld-it-pg16;      image=postgres:16; port=35416; driver=pgsql;   args=(-e POSTGRES_PASSWORD=root) ;;
    *) echo "unknown server $server"; exit 2 ;;
  esac
  printf '\n== %s (%s)\n' "$server" "$image"
  docker rm -f "$name" >/dev/null 2>&1
  if docker run -d --name "$name" "${args[@]}" -p "$port:$([ $driver = pgsql ] && echo 5432 || echo 3306)" "$image" >/dev/null &&
     wait_for "$driver" "$port" &&
     SQL_DASHBOARD_IT_DRIVER=$driver SQL_DASHBOARD_IT_PORT=$port vendor/bin/pest --group=integration; then
    RESULTS+=("pass  integration $image")
  else
    RESULTS+=("FAIL  integration $image"); FAILED=1
  fi
  [ -n "${KEEP:-}" ] || docker rm -f "$name" >/dev/null 2>&1
done

printf '\n== Integration summary\n'; printf '%s\n' "${RESULTS[@]}"
exit $FAILED
