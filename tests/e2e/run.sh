#!/usr/bin/env bash
# End-to-end checkout and webhook scenarios against a throwaway WordPress + WooCommerce in
# Docker, with the Trondealer API stubbed (mu-plugins/trondealer-e2e-stub.php). Needs Docker
# with compose. Exits non-zero if any scenario fails; always tears down.
#
#   bash tests/e2e/run.sh

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
export COMPOSE_PROJECT_NAME=trondealer-wc-e2e

trap 'docker compose down -v >/dev/null 2>&1 || true' EXIT
docker compose up -d --quiet-pull

wp() { docker compose exec -T cli wp "$@"; }

echo "Waiting for the database…"
for _ in $(seq 1 60); do wp db check >/dev/null 2>&1 && break; sleep 1; done

wp core install --url=http://wp --title=Trondealer-E2E --admin_user=admin \
	--admin_password="$(openssl rand -hex 12)" --admin_email=admin@example.test --skip-email
# Current WooCommerce requires a newer WordPress than the image ships
wp core update --quiet
wp core update-db --quiet
wp plugin install woocommerce --activate --quiet
wp plugin activate trondealer-payments --quiet
wp option update woocommerce_currency USD --quiet
echo "WordPress $(wp core version) · WooCommerce $(wp plugin get woocommerce --field=version)"

wp eval-file /tests/scenarios.php
