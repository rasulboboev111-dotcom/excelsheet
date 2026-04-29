<?php

namespace App\Console\Commands;

use App\Models\SheetAuditLog;
use Illuminate\Console\Command;

/**
 * Удаляет записи журнала аудита старше N дней (по умолчанию 90).
 *
 * Без этой команды sheet_audit_logs растёт без границ: на 1000 правок/день
 * за 3 года накопится миллион записей, пагинация на /audit-log замедлится
 * с 50ms до секунд.
 *
 * Запускается scheduler'ом раз в сутки (см. App\Console\Kernel::schedule).
 * Можно вызвать руками: php artisan audit-log:cleanup [--days=N] [--dry-run]
 */
class CleanupAuditLog extends Command
{
    protected $signature = 'audit-log:cleanup
                            {--days= : Удалять записи старше этого числа дней (по умолчанию из env AUDIT_LOG_RETENTION_DAYS = 90)}
                            {--dry-run : Только показать сколько будет удалено, без реального удаления}';

    protected $description = 'Удалить старые записи журнала аудита (retention policy)';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? env('AUDIT_LOG_RETENTION_DAYS', 90));
        if ($days < 1) {
            $this->error('--days должно быть >= 1.');
            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $query = SheetAuditLog::where('created_at', '<', $cutoff);
        $count = $query->count();

        $this->info(sprintf(
            '[%s] Найдено %d записей старше %d дней (до %s).',
            now()->toDateTimeString(),
            $count,
            $days,
            $cutoff->toDateTimeString()
        ));

        if ($count === 0) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN — ничего не удалено.');
            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Удалено: {$deleted} записей.");

        return self::SUCCESS;
    }
}
