#!/usr/bin/env bash
# Idempotent setup of the shoestring Lightsail host that runs development, staging and production side by side.
# Run as root from a copy of the repository on the server:
#   sudo PUBLIC_IP=<static ip> ADMIN_EMAIL=<email> BACKUP_BUCKET=<bucket> ./deploy/server/provision.sh
# Optional on the first run: BACKUP_AWS_KEY_ID / BACKUP_AWS_SECRET (wingora-backup-writer access key) and
# /root/wingora-deploy-keys/<environment>.pub (public half of each GitHub environment's DEPLOY_SSH_KEY).
# Every password and key is generated here and written only to root- or owner-readable files; nothing is printed.
set -euo pipefail

REPO=$(cd "$(dirname "$0")/../.." && pwd)
: "${PUBLIC_IP:?}" "${ADMIN_EMAIL:?}" "${BACKUP_BUCKET:?}"
IP_DASHED=${PUBLIC_IP//./-}
ENVIRONMENTS=(development staging production)
declare -A SUBDOMAIN=([development]=dev [staging]=staging [production]=app)
declare -A FPM_CHILDREN=([development]=3 [staging]=3 [production]=6)
export DEBIAN_FRONTEND=noninteractive

# --- Packages (no containers)
grep -rqs ondrej /etc/apt/sources.list.d/ || add-apt-repository -y ppa:ondrej/php
apt-get update -q
apt-get install -yq nginx mysql-server redis-server certbot python3-certbot-nginx fail2ban unzip \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath \
  php8.4-intl php8.4-gd php8.4-redis
command -v aws >/dev/null || [ -x /snap/bin/aws ] || snap install aws-cli --classic

# --- 2 GB of RAM is tight for three environments; swap absorbs bursts
if [ ! -f /swapfile ]; then
  fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile
  echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi
echo 'vm.swappiness=10' > /etc/sysctl.d/90-wingora.conf && sysctl -q -p /etc/sysctl.d/90-wingora.conf

# --- MySQL 8, local only
cat > /etc/mysql/mysql.conf.d/zz-wingora.cnf <<'CNF'
[mysqld]
bind-address = 127.0.0.1
mysqlx = OFF
performance_schema = OFF
innodb_buffer_pool_size = 256M
max_connections = 60
CNF

# --- PHP 8.4
cat > /etc/php/8.4/fpm/conf.d/90-wingora.ini <<'INI'
expose_php = Off
memory_limit = 256M
upload_max_filesize = 12M
post_max_size = 14M
opcache.enable = 1
opcache.memory_consumption = 96
opcache.validate_timestamps = 0
INI
cp /etc/php/8.4/fpm/conf.d/90-wingora.ini /etc/php/8.4/cli/conf.d/90-wingora.ini
sed -i 's/^opcache.enable = 1/opcache.enable = 0/' /etc/php/8.4/cli/conf.d/90-wingora.ini
rm -f /etc/php/8.4/fpm/pool.d/www.conf

# --- Nginx: no version banner, unknown hosts dropped
echo 'server_tokens off;' > /etc/nginx/conf.d/wingora.conf
rm -f /etc/nginx/sites-enabled/default
cat > /etc/nginx/sites-available/wingora-default <<'NGINX'
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    return 444;
}
NGINX
ln -sfn /etc/nginx/sites-available/wingora-default /etc/nginx/sites-enabled/wingora-default
cat > /etc/nginx/snippets/wingora-headers.conf <<'NGINX'
add_header Strict-Transport-Security "max-age=31536000" always;
add_header X-Content-Type-Options nosniff always;
add_header X-Frame-Options DENY always;
add_header Referrer-Policy strict-origin-when-cross-origin always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
NGINX

# --- Queue worker template (one instance per environment)
cat > /etc/systemd/system/wingora-queue@.service <<'UNIT'
[Unit]
Description=Wingora queue worker (%i)
After=network.target mysql.service
ConditionPathExists=/srv/wingora/%i/current/backend/artisan

[Service]
User=wingora-%i
Group=wingora-%i
WorkingDirectory=/srv/wingora/%i/current/backend
ExecStart=/usr/bin/php artisan queue:work database --sleep=3 --tries=3 --max-time=3600 --memory=128
Restart=always
RestartSec=5
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload

systemctl restart mysql
REDIS_ACL='user default off'$'\n'

for ENVIRONMENT in "${ENVIRONMENTS[@]}"; do
  USER_NAME=wingora-$ENVIRONMENT
  BASE=/srv/wingora/$ENVIRONMENT
  HOST=${SUBDOMAIN[$ENVIRONMENT]}.$IP_DASHED.sslip.io
  ENV_FILE=$BASE/shared/.env

  # Separate Unix user per environment; Nginx (www-data) may read, other environments may not.
  id -u "$USER_NAME" >/dev/null 2>&1 || useradd --system --create-home --home-dir "$BASE" --shell /bin/bash "$USER_NAME"
  usermod -aG "$USER_NAME" www-data
  install -d -m 750 -o "$USER_NAME" -g "$USER_NAME" "$BASE" "$BASE/releases" "$BASE/shared" "$BASE/shared/storage" \
    "$BASE/shared/storage/app" "$BASE/shared/storage/app/private" "$BASE/shared/storage/framework" \
    "$BASE/shared/storage/framework/cache" "$BASE/shared/storage/framework/sessions" \
    "$BASE/shared/storage/framework/views" "$BASE/shared/storage/logs"

  if [ ! -f "$ENV_FILE" ]; then
    umask 077
    sed -e "s/203-0-113-10/$IP_DASHED/g" \
        -e "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" \
        -e "s|^AUDIT_HMAC_KEY=.*|AUDIT_HMAC_KEY=$(openssl rand -hex 32)|" \
        -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -hex 24)|" \
        -e "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=$(openssl rand -hex 24)|" \
        "$REPO/backend/.env.$ENVIRONMENT.example" > "$ENV_FILE"
    umask 022
  fi
  chown "$USER_NAME:$USER_NAME" "$ENV_FILE" && chmod 600 "$ENV_FILE"
  DB_PASS=$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)
  REDIS_PASS=$(grep '^REDIS_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)

  # Separate database and database user; the user can reach only its own schema.
  mysql <<SQL
CREATE DATABASE IF NOT EXISTS wingora_$ENVIRONMENT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS 'wingora_$ENVIRONMENT'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER 'wingora_$ENVIRONMENT'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON wingora_$ENVIRONMENT.* TO 'wingora_$ENVIRONMENT'@'localhost';
SQL

  # Separate Redis user restricted to its own key prefix.
  REDIS_ACL+="user wingora_$ENVIRONMENT on >$REDIS_PASS ~wingora-$ENVIRONMENT:* &* +@all -@admin -@dangerous +flushdb +info"$'\n'

  # Separate PHP-FPM pool running as the environment's user.
  cat > "/etc/php/8.4/fpm/pool.d/wingora-$ENVIRONMENT.conf" <<POOL
[wingora-$ENVIRONMENT]
user = $USER_NAME
group = $USER_NAME
listen = /run/php/wingora-$ENVIRONMENT.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = ${FPM_CHILDREN[$ENVIRONMENT]}
pm.process_idle_timeout = 30s
pm.max_requests = 500
POOL

  # Same origin: the SPA from current/web, the API (/api, /sanctum, /up) from current/backend/public.
  cat > "/etc/nginx/sites-available/wingora-$ENVIRONMENT" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $HOST;
    root $BASE/current/web;
    index index.html;
    client_max_body_size 14m;

    location /assets/ {
        include snippets/wingora-headers.conf;
        expires 1y;
        try_files \$uri =404;
    }
    location / {
        include snippets/wingora-headers.conf;
        expires -1;
        try_files \$uri /index.html;
    }
    location ~ ^/(api|sanctum|up)(/|\$) {
        root $BASE/current/backend/public;
        fastcgi_pass unix:/run/php/wingora-$ENVIRONMENT.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
    }
}
NGINX
  ln -sfn "/etc/nginx/sites-available/wingora-$ENVIRONMENT" "/etc/nginx/sites-enabled/wingora-$ENVIRONMENT"

  # Scheduler, worker and the only root actions a deploy may take.
  echo "* * * * * $USER_NAME [ -f $BASE/current/backend/artisan ] && cd $BASE/current/backend && php artisan schedule:run >/dev/null 2>&1" \
    > "/etc/cron.d/wingora-$ENVIRONMENT"
  SUDO_CMDS="/usr/bin/systemctl reload php8.4-fpm, /usr/bin/systemctl restart wingora-queue@$ENVIRONMENT"
  [ "$ENVIRONMENT" = production ] && SUDO_CMDS+=", /usr/local/sbin/wingora-backup production"
  echo "$USER_NAME ALL=(root) NOPASSWD: $SUDO_CMDS" > "/etc/sudoers.d/wingora-$ENVIRONMENT"
  chmod 440 "/etc/sudoers.d/wingora-$ENVIRONMENT" && visudo -cqf "/etc/sudoers.d/wingora-$ENVIRONMENT"
  systemctl enable -q "wingora-queue@$ENVIRONMENT"

  # Deploy key: forced to non-interactive use, one key per environment.
  install -d -m 700 -o "$USER_NAME" -g "$USER_NAME" "$BASE/.ssh"
  if [ -f "/root/wingora-deploy-keys/$ENVIRONMENT.pub" ]; then
    echo "restrict $(cat "/root/wingora-deploy-keys/$ENVIRONMENT.pub")" > "$BASE/.ssh/authorized_keys"
    chown "$USER_NAME:$USER_NAME" "$BASE/.ssh/authorized_keys" && chmod 600 "$BASE/.ssh/authorized_keys"
  fi
done

# --- Redis: local only, cache-sized, one ACL user per environment
cat > /etc/redis/wingora.conf <<'CONF'
bind 127.0.0.1 -::1
maxmemory 128mb
maxmemory-policy allkeys-lru
aclfile /etc/redis/users.acl
CONF
grep -q '^include /etc/redis/wingora.conf' /etc/redis/redis.conf || echo 'include /etc/redis/wingora.conf' >> /etc/redis/redis.conf
printf '%s' "$REDIS_ACL" > /etc/redis/users.acl
chown redis:redis /etc/redis/users.acl && chmod 640 /etc/redis/users.acl

# --- Backups to S3 (put-only credentials)
install -d -m 700 /etc/wingora
echo "$BACKUP_BUCKET" > /etc/wingora/backup-bucket
if [ -n "${BACKUP_AWS_KEY_ID:-}" ]; then
  (umask 077; printf '[default]\naws_access_key_id = %s\naws_secret_access_key = %s\n' "$BACKUP_AWS_KEY_ID" "$BACKUP_AWS_SECRET" \
    > /etc/wingora/aws-backup-credentials)
fi
install -m 750 "$REPO/deploy/server/backup.sh" /usr/local/sbin/wingora-backup
# Escrow each environment's encryption keys in SSM Parameter Store (separate from the data backups).
if [ -f /etc/wingora/aws-backup-credentials ]; then
  for ENVIRONMENT in "${ENVIRONMENTS[@]}"; do
    grep -E '^(APP_KEY|AUDIT_HMAC_KEY)=' "/srv/wingora/$ENVIRONMENT/shared/.env" > "/etc/wingora/keys-$ENVIRONMENT"
    AWS_SHARED_CREDENTIALS_FILE=/etc/wingora/aws-backup-credentials PATH="$PATH:/snap/bin" aws ssm put-parameter --region us-east-1 \
      --name "/wingora/$ENVIRONMENT/keys" --type SecureString --overwrite --value "file:///etc/wingora/keys-$ENVIRONMENT" >/dev/null
    rm -f "/etc/wingora/keys-$ENVIRONMENT"
  done
fi
echo '15 3 * * * root /usr/local/sbin/wingora-backup >> /var/log/wingora-backup.log 2>&1' > /etc/cron.d/wingora-backup

systemctl restart redis-server php8.4-fpm
nginx -t -q && systemctl reload nginx

# --- HTTPS (Let's Encrypt) for each environment's sslip.io name
for ENVIRONMENT in "${ENVIRONMENTS[@]}"; do
  HOST=${SUBDOMAIN[$ENVIRONMENT]}.$IP_DASHED.sslip.io
  [ -d "/etc/letsencrypt/live/$HOST" ] || certbot --nginx --non-interactive --agree-tos -m "$ADMIN_EMAIL" --redirect -d "$HOST" \
    || echo "WARNING: certificate for $HOST failed; the environment needs HTTPS for secure cookies" >&2
done
echo "provisioned: dev, staging and app.$IP_DASHED.sslip.io"
