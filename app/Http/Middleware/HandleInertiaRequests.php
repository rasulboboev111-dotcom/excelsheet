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
                // Подключён ли Gmail у текущего юзера. Фронт показывает кнопку
                // «Отправить по почте» в Dashboard только если true.
                'gmailConnected' => $user ? $user->hasGoogleConnected() : false,
                'gmailEmail'     => $user?->google_email,
            ],
            // Flash-сообщения от GoogleAuthController после OAuth-callback'а.
            'flash' => [
                'success' => $request->session()->get('flash_success'),
                'error'   => $request->session()->get('flash_error'),
            ],
            'ziggy' => function () use ($request) {
                return array_merge((new Ziggy)->toArray(), [
                    'location' => $request->url(),
                ]);
            },
        ]);
    }
}
