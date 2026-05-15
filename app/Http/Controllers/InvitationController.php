<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Регистрация по приглашению. Публичной регистрации нет — попасть на форму
 * можно только по ссылке /invite/{token}, выданной админом. Токен живёт
 * до явного отзыва. Зарегистрированный юзер всегда получает базовую роль —
 * права админа/почты выдаются отдельно через /users.
 */
class InvitationController extends Controller
{
    /**
     * Админ создаёт новую ссылку-приглашение.
     */
    public function store(Request $request): RedirectResponse
    {
        $invitation = UserInvitation::create([
            'token'      => UserInvitation::generateToken(),
            'created_by' => Auth::id(),
        ]);

        return redirect()->route('users.index')
            ->with('flash_invite_token', $invitation->token);
    }

    /**
     * Публичная страница регистрации по токену. Доступна только гостям —
     * залогиненных редиректим на /dashboard. Если токен неизвестен или
     * отозван — 404, чтобы наличие/отсутствие токена не утекало.
     */
    public function show(string $token): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/dashboard');
        }

        $invitation = UserInvitation::where('token', $token)->active()->first();
        abort_if($invitation === null, HttpResponse::HTTP_NOT_FOUND);

        return Inertia::render('Auth/AcceptInvite', [
            'token' => $token,
        ]);
    }

    /**
     * Регистрация по приглашению. Создаёт юзера, логинит, инкрементит счётчик.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/dashboard');
        }

        $invitation = UserInvitation::where('token', $token)->active()->first();
        abort_if($invitation === null, HttpResponse::HTTP_NOT_FOUND);

        $payload = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(6)],
        ]);

        $user = User::create([
            'name'              => $payload['name'],
            'email'             => $payload['email'],
            'password'          => Hash::make($payload['password']),
            // Регистрация по инвайту от админа — email считаем подтверждённым,
            // как и при создании юзера админом вручную (см. UserController::store).
            'email_verified_at' => now(),
        ]);

        $invitation->increment('uses_count');

        event(new Registered($user));

        Auth::login($user);

        return redirect('/dashboard');
    }

    /**
     * Админ отзывает инвайт — после этого ссылка перестаёт работать.
     */
    public function destroy(UserInvitation $invitation): RedirectResponse
    {
        if ($invitation->revoked_at === null) {
            $invitation->update(['revoked_at' => now()]);
        }

        return redirect()->route('users.index');
    }
}
