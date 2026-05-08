<?php

namespace App\Jobs;

use App\Models\Sheet;
use App\Models\User;
use App\Services\GmailMailerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Async-отправка таблицы по почте через Gmail API.
 *
 * Сборка xlsx-вложения (PhpSpreadsheet) и сетевой запрос к Gmail вместе
 * блокируют request на 1-3 секунды для маленьких листов и до 10 секунд
 * для больших. В очереди юзер получает «отправлено» мгновенно (~50 мс),
 * email уходит фоном.
 *
 * Tries=3 + backoff=10 настроены на уровне worker'а в docker-compose
 * (queue:work --tries=3 --backoff=10), здесь дублировать не надо.
 */
class SendSheetEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Длительность за которую задача должна успеть. Если превысит —
     * worker убьёт процесс и retry. Соответствует --timeout=120 в compose.
     */
    public int $timeout = 120;

    public function __construct(
        public User $sender,
        public Sheet $sheet,
        public string $to,
        public string $subject,
        public string $body,
        public ?array $attachment,
    ) {
    }

    /**
     * Выполняется внутри queue:worker'а. Бросаем исключение → Laravel ретраит.
     */
    public function handle(GmailMailerService $mailer): void
    {
        $mailer->send(
            sender: $this->sender,
            to: $this->to,
            subject: $this->subject,
            body: $this->body,
            attachment: $this->attachment,
            isHtml: false,
        );
    }

    /**
     * Финал — после исчерпания retries. Логируем для дебага.
     * Юзер уже получил «письмо отправлено» 5 минут назад, но если Gmail
     * упорно не принимает — хотя бы в логах видно почему.
     */
    public function failed(\Throwable $e): void
    {
        \Log::warning('SendSheetEmailJob ultimately failed', [
            'sheet_id' => $this->sheet->id,
            'to'       => $this->to,
            'sender'   => $this->sender->email,
            'error'    => $e->getMessage(),
        ]);
    }
}
