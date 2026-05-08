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
 * ВАЖНО: НЕ передаём бинарный xlsx-attachment в конструктор. Laravel при
 * dispatch() JSON-кодирует аргументы джоба для очереди (даже sync queue
 * проходит через createPayload). Бинарные байты xlsx ломают UTF-8 валидацию
 * → InvalidPayloadException. Поэтому job получает только параметры
 * (sheet_id, to, subject, body, includeAttachment) и САМ собирает xlsx
 * в handle() через ту же helper-функцию контроллера.
 *
 * SerializesModels превращает User/Sheet в id, при handle их подгружает обратно.
 *
 * Tries=3 + backoff=10 настроены на уровне worker'а в docker-compose.
 */
class SendSheetEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(
        public User $sender,
        public Sheet $sheet,
        public string $to,
        public string $subject,
        public string $body,
        public bool $includeAttachment = true,
    ) {
    }

    /**
     * Выполняется внутри queue:worker'а. Бросаем исключение → Laravel ретраит.
     */
    public function handle(GmailMailerService $mailer): void
    {
        // Собираем xlsx прямо здесь — иначе бинарь не серилизуется в payload.
        $attachment = null;
        if ($this->includeAttachment) {
            $attachment = $this->buildXlsxAttachment();
        }

        $mailer->send(
            sender: $this->sender,
            to: $this->to,
            subject: $this->subject,
            body: $this->body,
            attachment: $attachment,
            isHtml: false,
        );
    }

    /**
     * Дублируем логику SheetController::buildXlsxAttachment() здесь — нужен
     * одинаковый формат вложения. Делать это прямо в job'е чтобы binary не
     * проходил через сериализацию payload'а очереди.
     */
    private function buildXlsxAttachment(): array
    {
        $columns = $this->sheet->columns ?? [];
        $rows = \App\Models\SheetData::where('sheet_id', $this->sheet->id)
            ->orderBy('row_index')->get();

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $safeName = preg_replace('/[^\p{L}\p{N}\s_\-]/u', '', $this->sheet->name) ?: 'Sheet1';
        $ws->setTitle(mb_substr($safeName, 0, 31));

        $colAddr = fn (int $i) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);

        // ВАЖНО: НЕ пишем авто-шапку (буквы A, B, C). Юзер сам пишет имена
        // колонок в первой строке своих данных (row_index=0). Если ставить
        // нашу шапку — данные сдвигаются на 1 строку, формулы вида =H2-G2
        // начинают ссылаться на чужие ячейки → #VALUE!. Клиентский экспорт
        // (resources/js/Composables/xlsxIO.js) тоже без авто-шапки —
        // делаем server идентично.
        //
        // row_index+1 — Excel 1-based, никакого +1 за header'ом.
        foreach ($rows as $r) {
            $rowNum = (int) $r->row_index + 1;
            $col = 1;
            foreach ($columns as $c) {
                $field = $c['field'] ?? null;
                if (!$field) { $col++; continue; }
                $val = $r->row_data[$field] ?? null;
                if ($val === null || is_array($val)) { $col++; continue; }

                $coord = $colAddr($col) . $rowNum;
                // Date — если у ячейки style.numberFormat='shortDate', значение
                // лежит как Excel-serial (число дней от 1900). PhpSpreadsheet
                // умеет писать его как Date с автоформатом 'dd.mm.yyyy'.
                $style = $r->row_data[$field . '_style'] ?? null;
                if (is_numeric($val) && is_array($style) && ($style['numberFormat'] ?? null) === 'shortDate') {
                    $ws->getCell($coord)->setValue((float) $val);
                    $ws->getStyle($coord)->getNumberFormat()->setFormatCode('dd.mm.yyyy');
                } else {
                    $this->writeSafeCellValue($ws, $coord, $val);
                }
                $col++;
            }
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $xlsxBytes = ob_get_clean();

        $filename = preg_replace('/[^\p{L}\p{N}\s_\-]/u', '_', $this->sheet->name) ?: 'sheet';
        return [
            'name' => $filename . '.xlsx',
            'data' => $xlsxBytes,
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    private function writeSafeCellValue($worksheet, string $coordinate, $val): void
    {
        if ($val === null || $val === '') return;
        if (is_string($val)) {
            // 1) Опасные формулы — пишем как текст (защита от formula injection).
            if (str_starts_with($val, '=')) {
                $body = substr($val, 1);
                $isDangerous = str_contains($body, '|')
                    || preg_match('/\b(?:cmd|DDE|EXEC|CALL|MSEXCEL|RTD|WEBSERVICE)\b/i', $body);
                if ($isDangerous) {
                    $worksheet->setCellValueExplicit(
                        $coordinate, $val,
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                    );
                    return;
                }
            }
            // 2) Длинные числа-как-строки (10+ цифр: телефоны, ИНН, артикулы).
            // Без TYPE_STRING PhpSpreadsheet auto-coerce'ит их в число → Excel
            // показывает «9,92987E+11» вместо «992987071106».
            if (preg_match('/^\d{10,}$/', $val)) {
                $worksheet->setCellValueExplicit(
                    $coordinate, $val,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
                return;
            }
        }
        $worksheet->setCellValue($coordinate, $val);
    }

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
