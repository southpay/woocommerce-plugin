#!/usr/bin/env bash
set -e

CMD=${1:-up}

case "$CMD" in
  up)
    docker compose up -d
    echo "Setting up WordPress, WooCommerce and SouthPay..."
    docker compose logs -f setup
    ;;
  down)
    docker compose down
    ;;
  reset)
    echo "Wiping all data and restarting..."
    docker compose down -v
    bash "$0" up
    ;;
  logs)
    docker compose logs -f wordpress
    ;;
  cli)
    shift
    docker compose run --rm setup wp "$@" --allow-root
    ;;
  *)
    echo "Usage: ./dev.sh [up|down|reset|logs|cli <wp-cli-args>]"
    ;;
esac
