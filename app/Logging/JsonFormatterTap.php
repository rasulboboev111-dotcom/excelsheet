<?php

namespace App\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Logger;

/**
 * Tap-класс для Monolog daily-channel: ставит JsonFormatter на все handlers.
 * Конфигурируется в config/logging.php → 'daily' => [..., 'tap' => [JsonFormatterTap::class]]
 *
 * Каждая строка лога становится одним JSON-объектом с полями
 * { "datetime", "channel", "level_name", "message", "context", "extra" } —
 * это формат, который из коробки парсят Loki / ELK / Grafana / Datadog.
 *
 * Локально текстовый формат удобнее — переключение через env LOG_JSON=false.
 */
class JsonFormatterTap
{
    public function __invoke(Logger $logger): void
    {
        if (!env('LOG_JSON', true)) {
            return; // оставляем текстовый формат как было
        }
        $formatter = new JsonFormatter(
            JsonFormatter::BATCH_MODE_NEWLINES,
            true /* appendNewline */
        );
        // Включаем stacktrace для exception (по умолчанию выключен у JsonFormatter).
        $formatter->includeStacktraces(true);
        // JSON-флаги:
        //   JSON_INVALID_UTF8_SUBSTITUTE — заменяет «битые» UTF-8-байты на U+FFFD
        //     (без этого Monolog кидает RuntimeException «Malformed UTF-8 characters»,
        //      и весь log-write валится → дальше ошибки в приложении).
        //   JSON_PARTIAL_OUTPUT_ON_ERROR — если что-то ещё пойдёт не так (циклические
        //     ссылки, NaN, etc.), вернёт частичный JSON вместо полного фейла.
        //   JSON_UNESCAPED_UNICODE/SLASHES — кириллица и пути читаемые.
        if (method_exists($formatter, 'setJsonOptions')) {
            $formatter->setJsonOptions(
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
        }

        foreach ($logger->getHandlers() as $handler) {
            $handler->setFormatter($formatter);
        }
    }
}
