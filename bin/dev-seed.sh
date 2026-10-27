#!/usr/bin/env bash
# Add a lot of rows to a dev site, so that jobs take many slices and the progress screens have something to show.
#   bin/dev-seed.sh <name> [rows]      (default 300000 rows in wp_postmeta, plus 5000 comments)
set -euo pipefail
SITES_DIR="${SITES_DIR:-$HOME/estm-dev-sites}"
name="${1:?Give the site name}"; rows="${2:-300000}"
cd "$SITES_DIR/$name"
if wp --allow-root db query "SELECT VERSION()" | grep -qi mariadb; then limit="SET SESSION max_recursive_iterations = 1000000;"; else limit="SET SESSION cte_max_recursion_depth = 1000000;"; fi
wp --allow-root db query "
$limit
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 1 + (n % 20), CONCAT('_seed_', n % 50), CONCAT('Seed value ', n, ' https://seed.example.com/page/', n, ' a:1:{s:3:\"url\";s:34:\"https://seed.example.com/page/', n, '\";}')
FROM (WITH RECURSIVE seq AS (SELECT 1 AS n UNION ALL SELECT n + 1 FROM seq WHERE n < $rows) SELECT n FROM seq) t;
INSERT INTO wp_comments (comment_post_ID, comment_author, comment_author_email, comment_author_url, comment_author_IP, comment_date, comment_date_gmt, comment_content, comment_approved, comment_type)
SELECT 1, CONCAT('Visitor ', n), CONCAT('visitor', n, '@customer.example'), '', CONCAT('203.0.113.', n % 250), NOW(), UTC_TIMESTAMP(), CONCAT('Comment number ', n), '1', 'comment'
FROM (WITH RECURSIVE seq AS (SELECT 1 AS n UNION ALL SELECT n + 1 FROM seq WHERE n < 5000) SELECT n FROM seq) t;
"
wp --allow-root db query "SELECT COUNT(*) AS postmeta_rows FROM wp_postmeta; SELECT COUNT(*) AS comments FROM wp_comments;"
