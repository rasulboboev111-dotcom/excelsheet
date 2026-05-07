<?php

namespace App\Services;

use App\Models\User;
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use RuntimeException;

/**
 * Отправка письма через Gmail API от имени конкретного юзера.
 *
 * Юзер должен быть подключен (см. GoogleAuthController). Сервис:
 *   1) Читает refresh_token юзера из БД (encrypted-cast делает это прозрачно).
 *   2) Если access_token истёк — обновляет через refresh_token.
 *   3) Строит MIME-сообщение (multipart, если есть вложение).
 *   4) Кодирует в base64url и шлёт на gmail.googleapis.com/.../messages/send.
 *
 * Ошибки кидаем как RuntimeException с понятным текстом — контроллер
 * перехватывает и возвращает юзеру 4xx/422 с сообщением.
 */
class GmailMailerService
{
    private GoogleClient $client;

    public function __construct()
    {
        $cfg = config('services.google');
        if (empty($cfg['client_id']) || empty($cfg['client_secret'])) {
            throw new RuntimeException('Google OAuth не настроен (GOOGLE_CLIENT_ID / SECRET в .env пустые).');
        }
        $this->client = new GoogleClient();
        $this->client->setClientId($cfg['client_id']);
        $this->client->setClientSecret($cfg['client_secret']);
    }

    /**
     * Отправить письмо.
     *
     * @param User   $sender      Юзер сайта, у которого подключён Gmail.
     * @param string $to          Email получателя.
     * @param string $subject     Тема.
     * @param string $body        Текст письма (plain или HTML — определяется $isHtml).
     * @param array  $attachment  ['name'=>'file.xlsx', 'data'=>bytes, 'mime'=>'application/vnd...']
     * @param bool   $isHtml
     */
    public function send(User $sender, string $to, string $subject, string $body, ?array $attachment = null, bool $isHtml = false): void
    {
        if (!$sender->hasGoogleConnected()) {
            throw new RuntimeException('Юзер не подключил Gmail. Сначала перейдите в профиль и нажмите «Подключить Gmail».');
        }

        // Обновляем access_token если протух (или скоро протухнет).
        $this->ensureFreshAccessToken($sender);

        $this->client->setAccessToken([
            'access_token'  => $sender->google_access_token,
            'refresh_token' => $sender->google_refresh_token,
        ]);

        // Собираем MIME.
        $mime = $this->buildMimeMessage(
            from: $sender->google_email,
            fromName: $sender->name,
            to: $to,
            subject: $subject,
            body: $body,
            isHtml: $isHtml,
            attachment: $attachment,
        );

        // Кодируем в base64url (URL-safe Base64, как требует Gmail API).
        $raw = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');

        $message = new Message();
        $message->setRaw($raw);

        $service = new Gmail($this->client);
        try {
            $service->users_messages->send('me', $message);
        } catch (\Google\Service\Exception $e) {
            // Парсим JSON-ответ Google для понятной ошибки.
            $details = json_decode($e->getMessage(), true);
            $rawMsg = $details['error']['message'] ?? $e->getMessage();

            // Переводим распространённые ошибки в понятный для юзера текст.
            // Сырое сообщение оставляем в logger через SheetController::email().
            $friendly = $this->translateGoogleError($rawMsg, $e->getCode());

            throw new RuntimeException($friendly, $e->getCode(), $e);
        }
    }

    /**
     * Преобразует технические ошибки Gmail API в текст для модалки юзера.
     * Полная техника по-прежнему пишется в laravel.log с user_id и sheet_id —
     * админ всегда сможет посмотреть точную причину.
     */
    private function translateGoogleError(string $rawMsg, int $code): string
    {
        $low = strtolower($rawMsg);

        if (str_contains($low, 'insufficient authentication scopes') || str_contains($low, 'insufficient_scope')) {
            return 'Не удалось отправить письмо. Откройте Профиль → нажмите «Отключить» рядом с Gmail и подключитесь заново.';
        }

        if (str_contains($low, 'invalid_grant') || str_contains($low, 'token has been expired') || str_contains($low, 'token has been revoked')) {
            return 'Доступ к Gmail был отозван. Зайдите в Профиль и переподключите Gmail.';
        }

        if (str_contains($low, 'daily limit') || str_contains($low, 'quota') || str_contains($low, 'rate limit')) {
            return 'Дневной лимит Gmail исчерпан (500 писем/сутки на бесплатном аккаунте). Попробуйте через сутки.';
        }

        if (str_contains($low, 'invalid to header') || str_contains($low, 'invalid recipient')) {
            return 'Адрес получателя некорректен.';
        }

        if (str_contains($low, 'attachment') && str_contains($low, 'large')) {
            return 'Файл слишком большой для отправки через Gmail (лимит ~25 МБ).';
        }

        // Для всех остальных — короткое нейтральное сообщение без технических деталей.
        return 'Не удалось отправить письмо. Попробуйте ещё раз или обратитесь к администратору.';
    }

    /**
     * Если access_token истечёт меньше чем через 60 секунд — обновляем через refresh_token.
     */
    private function ensureFreshAccessToken(User $sender): void
    {
        $expires = $sender->google_token_expires_at;
        if ($expires && $expires->isFuture() && now()->diffInSeconds($expires, false) > 60) {
            return; // ещё валиден
        }

        $this->client->setClientId(config('services.google.client_id'));
        $this->client->setClientSecret(config('services.google.client_secret'));
        try {
            $newToken = $this->client->fetchAccessTokenWithRefreshToken($sender->google_refresh_token);
        } catch (\Throwable $e) {
            throw new RuntimeException('Не удалось обновить Gmail токен: ' . $e->getMessage());
        }

        if (isset($newToken['error'])) {
            // Refresh token отозван юзером в Google → нужно переподключить.
            throw new RuntimeException(
                'Gmail-доступ отозван (' . ($newToken['error_description'] ?? $newToken['error']) . '). '
                . 'Зайдите в профиль и переподключите Gmail.'
            );
        }

        $sender->google_access_token = $newToken['access_token'];
        $sender->google_token_expires_at = now()->addSeconds($newToken['expires_in'] ?? 3600);
        // refresh_token обычно НЕ возвращается при refresh (остаётся прежний).
        if (!empty($newToken['refresh_token'])) {
            $sender->google_refresh_token = $newToken['refresh_token'];
        }
        $sender->save();
    }

    /**
     * Собирает MIME RFC 5322 сообщение.
     * Если есть вложение — multipart/mixed; иначе — однопарт.
     */
    private function buildMimeMessage(string $from, string $fromName, string $to, string $subject, string $body, bool $isHtml, ?array $attachment): string
    {
        // RFC 2047 — кодируем заголовки в UTF-8 base64.
        $encodeHeader = fn (string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
        // ЗАЩИТА от MIME-header-injection (RFC 5322): любые CR/LF в значениях,
        // которые попадают в заголовки в сыром виде (`From: <email>`, `To: email`),
        // позволят атакующему дописать произвольные заголовки (Bcc/Cc).
        // base64-кодируемые поля (subject, fromName, attachment name) безопасны
        // сами по себе, но всё равно режем — на случай если кодирование сломается.
        $stripNewlines = fn (string $s) => preg_replace('/[\r\n\0]+/', ' ', $s);
        $from     = $stripNewlines($from);
        $to       = $stripNewlines($to);
        $fromName = $stripNewlines($fromName);
        $subject  = $stripNewlines($subject);

        // Валидируем from-email — он берётся из User->google_email и теоретически
        // может быть скомпрометирован (через подмену OAuth-callback'а). Если не
        // валиден как email, отказываемся отправлять (а не пытаемся «починить»).
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Некорректный from-email у отправителя.');
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Некорректный email получателя.');
        }

        $boundary = 'BOUNDARY_' . bin2hex(random_bytes(16));
        $contentType = $isHtml ? 'text/html' : 'text/plain';

        $headers = [
            'From: ' . $encodeHeader($fromName) . ' <' . $from . '>',
            'To: ' . $to,
            'Subject: ' . $encodeHeader($subject),
            'MIME-Version: 1.0',
        ];

        if (!$attachment) {
            // Простое сообщение без вложений.
            $headers[] = 'Content-Type: ' . $contentType . '; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body));
        }

        // Multipart: тело + вложение.
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

        $parts = [];
        // Часть 1: тело.
        $parts[] = "--{$boundary}\r\n"
            . "Content-Type: {$contentType}; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($body));

        // Часть 2: вложение.
        $attName = $encodeHeader($attachment['name']);
        $attMime = $attachment['mime'] ?? 'application/octet-stream';
        $parts[] = "--{$boundary}\r\n"
            . "Content-Type: {$attMime}; name=\"{$attName}\"\r\n"
            . "Content-Disposition: attachment; filename=\"{$attName}\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($attachment['data']));

        $parts[] = "--{$boundary}--";

        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
    }
}
