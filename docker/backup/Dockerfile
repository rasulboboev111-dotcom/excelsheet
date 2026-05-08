# Минимальный backup-контейнер: alpine + postgresql-client (pg_dump) + rclone +
# busybox crond. Один процесс — crond, тикает раз в минуту, по расписанию
# вызывает /usr/local/bin/backup.sh. Образ ~50 МБ.
FROM alpine:3.20

RUN apk add --no-cache \
        bash \
        coreutils \
        findutils \
        gzip \
        postgresql16-client \
        rclone \
        tini \
        tzdata

COPY backup.sh /usr/local/bin/backup.sh
RUN chmod +x /usr/local/bin/backup.sh

# Crontab: каждый день в 03:00 UTC. Можно переопределить через BACKUP_CRON env
# (формат стандартный 5-полей crontab) — entrypoint его подхватит.
ENV BACKUP_CRON="0 3 * * *"

# Entrypoint пишет crontab из BACKUP_CRON и стартует crond в foreground.
RUN printf '#!/bin/sh\nset -eu\necho "${BACKUP_CRON} /usr/local/bin/backup.sh >> /var/log/backup.log 2>&1" > /etc/crontabs/root\necho "[backup] cron schedule: ${BACKUP_CRON}"\ntouch /var/log/backup.log\ntail -F /var/log/backup.log &\nexec crond -f -d 8\n' > /entrypoint.sh \
    && chmod +x /entrypoint.sh

ENTRYPOINT ["/sbin/tini", "--", "/entrypoint.sh"]
