#!/usr/bin/env bash
# Nightly backup (root cron) and pre-migration backup (release.sh, production). Streams a consistent
# MySQL dump and the private file store of one or all environments to the S3 backup bucket.
# Usage: wingora-backup [development|staging|production]   (no argument = all)
set -euo pipefail

BUCKET=$(cat /etc/wingora/backup-bucket)
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
ENVIRONMENTS=${1:-development staging production}
export PATH="$PATH:/snap/bin" AWS_SHARED_CREDENTIALS_FILE=/etc/wingora/aws-backup-credentials AWS_DEFAULT_REGION=us-east-1

for ENVIRONMENT in $ENVIRONMENTS; do
  case "$ENVIRONMENT" in development|staging|production) ;; *) echo "unknown environment: $ENVIRONMENT" >&2; exit 2 ;; esac
  mysqldump --single-transaction --quick --routines --triggers --no-tablespaces "wingora_$ENVIRONMENT" \
    | gzip -9 | aws s3 cp - "s3://$BUCKET/$ENVIRONMENT/db/$STAMP.sql.gz" --only-show-errors
  tar -C "/srv/wingora/$ENVIRONMENT/shared/storage/app" -czf - private \
    | aws s3 cp - "s3://$BUCKET/$ENVIRONMENT/files/$STAMP.tar.gz" --only-show-errors
  echo "backed up $ENVIRONMENT at $STAMP"
done
