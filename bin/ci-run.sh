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
  # GitHub cuts an annotation at about 4,000 characters; keep the end of the output, where the summary is.
  msg="$(tail -c 3600 "$out")"
  # Workflow commands need these characters escaped.
  msg="${msg//'%'/'%25'}"
  msg="${msg//$'\r'/'%0D'}"
  msg="${msg//$'\n'/'%0A'}"
  echo "::error title=${title} failed::${msg}"
fi

rm -f "$out"
exit "$code"
