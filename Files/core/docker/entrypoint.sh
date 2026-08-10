#!/bin/bash
set -e

echo "==> Waiting for MySQL..."
until php -r "new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
  echo "   MySQL not ready, retrying..."
  sleep 3
done
echo "   MySQL is ready!"

echo "==> Creating framework tables..."
# Buat tabel framework Laravel yang diperlukan (cache, sessions, jobs)
php -r "
\$pdo = new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');
\$pdo->exec(\"
  CREATE TABLE IF NOT EXISTS cache (
    \`key\` VARCHAR(255) NOT NULL PRIMARY KEY,
    value MEDIUMTEXT NOT NULL,
    expiration INT NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
\");
\$pdo->exec(\"
  CREATE TABLE IF NOT EXISTS cache_locks (
    \`key\` VARCHAR(255) NOT NULL PRIMARY KEY,
    owner VARCHAR(255) NOT NULL,
    expiration INT NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
\");
\$pdo->exec(\"
  CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(255) NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    payload LONGTEXT NOT NULL,
    last_activity INT NOT NULL,
    INDEX sessions_user_id_index (user_id),
    INDEX sessions_last_activity_index (last_activity)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
\");
\$pdo->exec(\"
  CREATE TABLE IF NOT EXISTS jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL,
    reserved_at INT UNSIGNED NULL,
    available_at INT UNSIGNED NOT NULL,
    created_at INT UNSIGNED NOT NULL,
    INDEX jobs_queue_index (queue)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
\");
\$pdo->exec(\"
  CREATE TABLE IF NOT EXISTS job_batches (
    id VARCHAR(255) NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    total_jobs INT NOT NULL,
    pending_jobs INT NOT NULL,
    failed_jobs INT NOT NULL,
    failed_job_ids LONGTEXT NOT NULL,
    options MEDIUMTEXT NULL,
    cancelled_at INT NULL,
    created_at INT NOT NULL,
    finished_at INT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
\");
\$pdo->exec(\"
  CREATE TABLE IF NOT EXISTS failed_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(255) NOT NULL UNIQUE,
    connection TEXT NOT NULL,
    queue TEXT NOT NULL,
    payload LONGTEXT NOT NULL,
    exception LONGTEXT NOT NULL,
    failed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
\");
echo '   Framework tables created.' . PHP_EOL;
"

echo "==> Running artisan setup..."
cd /var/www/html/core

# Generate APP_KEY
php artisan key:generate --force --no-interaction

# Storage link
php artisan storage:link --force --no-interaction 2>/dev/null || echo "   Storage link skipped."

# Optimize Laravel (config + view cache, skip route karena banyak duplicate name)
echo "==> Optimizing Laravel..."
php artisan config:cache --no-interaction
php artisan view:cache --no-interaction

# Copy/merge assets dari root (lokasi lama) ke public/assets
if [ -d /var/www/html/assets ]; then
  echo "==> Copying assets to core/public/assets..."
  cp -r /var/www/html/assets/* /var/www/html/core/public/assets/
fi

echo "==> Starting supervisor..."
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf
