<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SheetAuditLog;
use Google\Client as GoogleClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OAuth-flow подключения юзером своего Gmail-аккаунта.
 *
 * /auth/google/connect    — редиректит юзера на Google authorize URL.
 *                           Юзер выбирает аккаунт → разрешает gmail.send →
 *                           Google отправляет его обратно на /auth/google/callback.
 * /auth/google/callback   — принимает code, обменивает на refresh_token,
 *                           сохраняет в users (encrypted).
 * /auth/google/disconnect — обнуляет токены + отзывает на стороне Google.
 *
 * Все три — только для авторизованных юзеров (auth middleware на роуте).
 *
 * State-параметр (CSRF): храним случайный токен в session, проверяем на callback.
 * Без этого злоумышленник может подсунуть свой code и привязать СВОЙ Gmail к аккаунту жертвы.
 */
class GoogleAuthController extends Controller
{
    private function client(): GoogleClient
    {
        $cfg = config('services.google');
        $client = new GoogleClient();
        $client->setClientId($cfg['client_id']);
        $client->setClientSecret($cfg['client_secret']);
        $client->setRedirectUri($cfg['redirect_uri']);
        // offline + prompt=consent — гарантирует выдачу refresh_token (без consent
        // Google вернёт только access_token, и при истечении сайт не сможет отправлять).
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setScopes($cfg['scopes']);
        // include_granted_scopes — добавляет ранее выданные scope'ы к новым,
        // удобно если юзер потом захочет дополнительный доступ.
        $client->setIncludeGrantedScopes(true);
        return $client;
    }

    public function connect(Request $request)
    {
        $cfg = config('services.google');
        if (empty($cfg['client_id']) || empty($cfg['client_secret'])) {
            abort(500, 'Google OAuth не настроен (GOOGLE_CLIENT_ID / SECRET в .env пустые).');
        }

        // CSRF state: случайный токен, проверим на callback.
        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);
        $client = $this->client();
        $client->setState($state);

        return redirect()->away($client->createAuthUrl());
    }

    public function callback(Request $request)
    {
        // 1. Если юзер отказался — Google вернёт ?error=access_denied
        if ($request->has('error')) {
            return redirect()->route('profile.edit')
                ->with('flash_error', 'Подключение Gmail отменено: ' . $request->query('error'));
        }

        // 2. CSRF state-проверка.
        $expectedState = $request->session()->pull('google_oauth_state');
        if (empty($expectedState) || $expectedState !== $request->query('state')) {
            return redirect()->route('profile.edit')
                ->with('flash_error', 'Ошибка безопасности (state mismatch). Попробуйте ещё раз.');
        }

        // 3. Меняем code на токены.
        $client = $this->client();
        try {
            $token = $client->fetchAccessTokenWithAuthCode($request->query('code'));
        } catch (\Throwable $e) {
            Log::warning('Google OAuth token exchange failed: ' . $e->getMessage());
            return redirect()->route('profile.edit')
                ->with('flash_error', 'Не удалось получить токен от Google.');
        }

        if (isset($token['error'])) {
            return redirect()->route('profile.edit')
                ->with('flash_error', 'Google отклонил code: ' . ($token['error_description'] ?? $token['error']));
        }

        // 4. Получаем профиль (email, sub) — нужен sub для google_id, email для отображения.
        $client->setAccessToken($token);
        try {
            $oauth = new \Google\Service\Oauth2($client);
            $profile = $oauth->userinfo->get();
            $googleId    = $profile->getId();
            $googleEmail = $profile->getEmail();
        } catch (\Throwable $e) {
            return redirect()->route('profile.edit')
                ->with('flash_error', 'Не удалось получить профиль Google: ' . $e->getMessage());
        }

        // 5. Сохраняем в users.
        $user = Auth::user();
        $user->google_id              = $googleId;
        $user->google_email           = $googleEmail;
        $user->google_access_token    = $token['access_token'];
        // refresh_token приходит ТОЛЬКО на первом подключении (или если prompt=consent).
        // Если юзер уже подключал, Google может НЕ вернуть refresh_token — в таком случае
        // оставляем старый.
        if (!empty($token['refresh_token'])) {
            $user->google_refresh_token = $token['refresh_token'];
        }
        $user->google_token_expires_at = now()->addSeconds($token['expires_in'] ?? 3600);
        $user->google_connected_at     = now();
        $user->save();

        // Audit
        try {
            SheetAuditLog::create([
                'user_id' => $user->id,
                'sheet_id' => null,
                'action' => 'gmail_connected',
                'details' => ['gmail_email' => $googleEmail],
                'ip' => $request->ip(),
            ]);
        } catch (\Throwable $e) { /* не блокируем основной flow */ }

        return redirect()->route('profile.edit')
            ->with('flash_success', "Gmail подключён: {$googleEmail}. Теперь можно отправлять письма с сайта.");
    }

    public function disconnect(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->hasGoogleConnected()) {
            return redirect()->route('profile.edit');
        }

        // Best-effort revoke на стороне Google. Если не получится — всё равно чистим БД.
        try {
            $client = $this->client();
            $client->setAccessToken([
                'access_token'  => $user->google_access_token,
                'refresh_token' => $user->google_refresh_token,
            ]);
            $client->revokeToken($user->google_refresh_token);
        } catch (\Throwable $e) {
            Log::info('Google revoke failed (non-fatal): ' . $e->getMessage());
        }

        $previousEmail = $user->google_email;
        $user->google_id              = null;
        $user->google_email           = null;
        $user->google_refresh_token   = null;
        $user->google_access_token    = null;
        $user->google_token_expires_at = null;
        $user->google_connected_at    = null;
        $user->save();

        try {
            SheetAuditLog::create([
                'user_id' => $user->id,
                'sheet_id' => null,
                'action' => 'gmail_disconnected',
                'details' => ['gmail_email' => $previousEmail],
                'ip' => $request->ip(),
            ]);
        } catch (\Throwable $e) {}

        return redirect()->route('profile.edit')
            ->with('flash_success', 'Gmail отключён.');
    }
}
