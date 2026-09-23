#!/bin/bash
#
# Install the Matomo that TrackingCodeGeneratorIntegrationTest compares against, and
# record how to reach it.
#
# Runs inside the web container on purpose so Matomo remembers the correct host as
# the trusted host and tracker URL.

set -eu -o pipefail

URL="${MATOMO_URL:-http://matomo/}"
LOGIN="${MATOMO_LOGIN:-admin}"
PASSWORD="${MATOMO_PASSWORD:-Fid3lity_Test_Pass}"
SITE_NAME="${MATOMO_SITE_NAME:-Tracking code fidelity}"
SITE_URL="${MATOMO_SITE_URL:-https://example.org}"
DB_HOST="${MATOMO_DB_HOST:-db}"
DB_NAME="${MATOMO_DB_NAME:-matomo}"
DB_USER="${MATOMO_DB_USER:-db}"
DB_PASSWORD="${MATOMO_DB_PASSWORD:-db}"
OUT="${WP_MATOMO_LIVE_MATOMO_FILE:-/tmp/wp-matomo-live-matomo.json}"

case "$URL" in */) ;; *) URL="${URL}/" ;; esac
INDEX="${URL}index.php"

JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT

wizard_failed() {
	local action="$1" expect="$2" what="$3"
	cat >&2 <<EOF

The Matomo setup wizard did not advance from '${action}' to '${expect}': ${what}.

Matomo ships no non-interactive installer, so this script drives its setup wizard,
whose steps and form fields are internal to Matomo rather than an API. If this began
failing after a Matomo release, they are what to check, against that release's
plugins/Installation/Controller.php and plugins/Installation/Form*.php.
EOF
	exit 1
}

# every step of the wizard that takes a form answers 302 to the next step when it
# accepted the form, and 200 with the form and its errors when it did not
post_step() {
	local action="$1" expect="$2"
	shift 2

	local result
	result="$(curl -sS -b "$JAR" -c "$JAR" -m 180 -o /dev/null \
		-w '%{http_code} %{redirect_url}' \
		-X POST "${INDEX}?module=Installation&action=${action}" "$@")"

	case "$result" in
		302*"action=${expect}"*) echo "    ${action} -> ${expect}" ;;
		*) wizard_failed "$action" "$expect" "it answered ${result}" ;;
	esac
}

get_step() {
	local action="$1" expect="${2:-}"

	local body
	body="$(curl -sS -b "$JAR" -c "$JAR" -m 180 --fail \
		"${INDEX}?module=Installation&action=${action}")" \
		|| wizard_failed "$action" "${expect:-itself}" 'it did not answer successfully'

	if [ -n "$expect" ] && ! printf '%s' "$body" | grep -q "action=${expect}"; then
		wizard_failed "$action" "$expect" 'it did not offer that as the next step'
	fi
	echo "    ${action}${expect:+ -> $expect}"
}

echo "==> Creating the ${DB_NAME} database on the ${DB_HOST} service"
# root/root is what ddev's database service is set up with
mysql -h "$DB_HOST" -u root -proot -e \
	"CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;
	 GRANT ALL ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
	 FLUSH PRIVILEGES;"

echo "==> Waiting for ${URL}"
code=000
for attempt in $(seq 1 60); do
	code="$(curl -s -o /dev/null -w '%{http_code}' -m 5 "$URL" || true)"
	if [ "$code" != "000" ]; then
		echo "    answered HTTP ${code} after ${attempt} attempt(s)"
		break
	fi
	sleep 2
done
if [ "$code" = "000" ]; then
	echo "Nothing answered at ${URL}. Start it with 'ddev wp-matomo:matomo up'." >&2
	exit 1
fi

echo "==> Running the setup wizard"
get_step welcome
get_step systemCheck

post_step databaseSetup tablesCreation \
	--data-urlencode "host=${DB_HOST}" \
	--data-urlencode "username=${DB_USER}" \
	--data-urlencode "password=${DB_PASSWORD}" \
	--data-urlencode "dbname=${DB_NAME}" \
	--data-urlencode 'tables_prefix=matomo_' \
	--data-urlencode 'adapter=PDO\MYSQL' \
	--data-urlencode 'schema=Mysql' \
	--data-urlencode 'submit=Next'

get_step tablesCreation setupSuperUser

post_step setupSuperUser firstWebsiteSetup \
	--data-urlencode "login=${LOGIN}" \
	--data-urlencode "password=${PASSWORD}" \
	--data-urlencode "password_bis=${PASSWORD}" \
	--data-urlencode 'email=admin@example.com' \
	--data-urlencode 'submit=Next'

post_step firstWebsiteSetup trackingCode \
	--data-urlencode "siteName=${SITE_NAME}" \
	--data-urlencode "url=${SITE_URL}" \
	--data-urlencode 'timezone=UTC' \
	--data-urlencode 'ecommerce=0' \
	--data-urlencode 'submit=Next'

# the two steps left only show the tracking code and a summary. posting the last one
# is what takes the installation out of progress, so the wizard stops answering for
# every other page.
curl -sS -b "$JAR" -c "$JAR" -m 180 -o /dev/null \
	-X POST "${INDEX}?module=Installation&action=finished" \
	--data-urlencode 'submit=Continue'
echo "    finished"

api_value() {
	php -r '
		$answer = json_decode( stream_get_contents( STDIN ), true );
		if ( isset( $answer["result"] ) && "error" === $answer["result"] ) {
			fwrite( STDERR, "Matomo answered: " . $answer["message"] . "\n" );
			exit( 1 );
		}
		echo isset( $answer["value"] ) ? $answer["value"] : "";
	'
}

echo "==> Minting an API token"

TOKEN="$(curl -sS -m 60 -X POST "$INDEX" \
	-d 'module=API' -d 'format=json' \
	-d 'method=UsersManager.createAppSpecificTokenAuth' \
	--data-urlencode "userLogin=${LOGIN}" \
	--data-urlencode "passwordConfirmation=${PASSWORD}" \
	--data-urlencode 'description=wp-matomo tracking code fidelity test' \
	-d 'expireHours=0' | api_value)"

if [ -z "$TOKEN" ]; then
	echo "Matomo did not answer with a token." >&2
	exit 1
fi

VERSION="$(curl -sS -m 60 -X POST "$INDEX" \
	-d 'module=API' -d 'format=json' -d 'method=API.getMatomoVersion' \
	-d 'force_api_session=0' --data-urlencode "token_auth=${TOKEN}" | api_value)"

if [ -z "$VERSION" ]; then
	echo "The token Matomo answered with does not work." >&2
	exit 1
fi

php -r '
	file_put_contents(
		$argv[1],
		json_encode(
			array(
				"url"     => $argv[2],
				"token"   => $argv[3],
				"id_site" => 1,
				"version" => $argv[4],
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n"
	);
' "$OUT" "$URL" "$TOKEN" "$VERSION"

cat <<EOF

  Matomo ${VERSION} is installed at ${URL}
  login       : ${LOGIN} / ${PASSWORD}
  recorded in : ${OUT}

  compare     : ddev wp-matomo:test --filter TrackingCodeGeneratorIntegrationTest
EOF
