<?php

namespace App\Http\Middleware;

use App\Models\Sheet;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): string|null
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        return array_merge(parent::share($request), [
            'auth' => [
                'user'    => $user,
                'isAdmin' => $user ? Sheet::userIsAdmin($user) : false,
                // Право пользоваться почтовой отправкой. Админ всегда может;
                // обычный юзер — если ему выдан Spatie permission 'send-mail'.
                // Фронт по этому флагу скрывает кнопки «Подключить Gmail» и «Отправить».
                'canSendMail' => $user ? Sheet::userCanSendMail($user) : false,
                // Подключён ли Gmail у текущего юзера. Кнопка «Отправить» в
                // Dashboard работает только когда canSendMail && gmailConnected.
                'gmailConnected' => $user ? $user->hasGoogleConnected() : false,
                'gmailEmail'     => $user?->google_email,
            ],
            // Flash-сообщения от GoogleAuthController после OAuth-callback'а
            // + только что созданный токен инвайта (показать ссылку админу).
            'flash' => [
                'success'      => $request->session()->get('flash_success'),
                'error'        => $request->session()->get('flash_error'),
                'invite_token' => $request->session()->get('flash_invite_token'),
            ],
            'ziggy' => function () use ($request) {
                return array_merge((new Ziggy)->toArray(), [
                    'location' => $request->url(),
                ]);
            },
        ]);
    }
}
