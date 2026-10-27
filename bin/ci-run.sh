#!/usr/bin/env bash
# Run one command in CI. If it fails, the end of its output is also shown as an annotation on the run,
# so the reason is visible on the Checks tab without opening the raw log.
#
#   bin/ci-run.sh "PHPStan" composer stan
set -uo pipefail

title="$1"
shift
out="$(mktemp)"
"$@" 2>&1 | tee "$out"
code="${PIPESTATUS[0]}"

if [ "$code" -ne 0 ]; then
  msg="$(tail -c 40000 "$out")"
  # Workflow commands need these characters escaped.
  msg="${msg//'%'/'%25'}"
  msg="${msg//$'\r'/'%0D'}"
  msg="${msg//$'\n'/'%0A'}"
  echo "::error title=${title} failed::${msg}"
fi

rm -f "$out"
exit "$code"
