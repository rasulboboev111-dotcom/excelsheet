<?php

namespace App\Http\Controllers;

use App\Models\Sheet;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index()
    {
        $users = User::orderBy('id')->get(['id', 'name', 'email', 'created_at', 'google_email'])
            ->map(function ($u) {
                return [
                    'id'              => $u->id,
                    'name'            => $u->name,
                    'email'           => $u->email,
                    'is_admin'        => Sheet::userIsAdmin($u),
                    'can_send_mail'   => Sheet::userCanSendMail($u),
                    'gmail_connected' => $u->hasGoogleConnected(),
                    'gmail_email'     => $u->google_email,
                    'created_at'      => $u->created_at?->toDateTimeString(),
                ];
            });

        // Активные ссылки-приглашения — рендерятся в той же странице. Полную
        // ссылку собираем здесь, чтобы фронт мог сразу её показать/скопировать.
        $invitations = UserInvitation::active()
            ->with('creator:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(function ($inv) {
                return [
                    'id'         => $inv->id,
                    'url'        => route('invitations.show', $inv->token),
                    'uses_count' => $inv->uses_count,
                    'created_by' => $inv->creator?->name,
                    'created_at' => $inv->created_at?->toDateTimeString(),
                ];
            });

        return Inertia::render('Users/Index', [
            'users'       => $users,
            'invitations' => $invitations,
        ]);
    }

    public function store(Request $request)
    {
        $payload = $request->validate([
            'name'          => 'required|string|max:255',
            'email'         => 'required|string|email|max:255|unique:users,email',
            'password'      => ['required', 'confirmed', Password::min(6)],
            'is_admin'      => 'sometimes|boolean',
            'can_send_mail' => 'sometimes|boolean',
        ]);

        $user = User::create([
            'name'              => $payload['name'],
            'email'             => $payload['email'],
            'password'          => Hash::make($payload['password']),
            'email_verified_at' => now(), // админ создаёт юзера → почта считается подтверждённой
        ]);

        if (!empty($payload['is_admin'])) {
            Sheet::makeUserAdmin($user);
        }
        if (!empty($payload['can_send_mail']) && empty($payload['is_admin'])) {
            // Админу permission давать не нужно — у него и так всё.
            Sheet::grantMailPermission($user);
        }

        return redirect()->route('users.index');
    }

    public function update(Request $request, User $user)
    {
        $payload = $request->validate([
            'name'          => 'required|string|max:255',
            'email'         => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password'      => ['nullable', 'confirmed', Password::min(6)],
            'is_admin'      => 'sometimes|boolean',
            'can_send_mail' => 'sometimes|boolean',
        ]);

        $user->name  = $payload['name'];
        $user->email = $payload['email'];
        if (!empty($payload['password'])) {
            $user->password = Hash::make($payload['password']);
        }
        $user->save();

        // Управление ролью admin. Запрещаем снять admin-роль с самого себя —
        // иначе можно случайно остаться без админа в системе.
        $wantsAdmin = !empty($payload['is_admin']);
        $isSelf = (int) $user->id === (int) Auth::id();
        $isAdmin = Sheet::userIsAdmin($user);
        if ($wantsAdmin && !$isAdmin) {
            Sheet::makeUserAdmin($user);
        } elseif (!$wantsAdmin && $isAdmin && !$isSelf) {
            Sheet::removeUserAdmin($user);
        }

        // Управление правом на отправку почты. Админу permission не нужен
        // (у него и так всё), но и не мешает — не трогаем флаг для админов.
        if (!Sheet::userIsAdmin($user)) {
            $wantsMail = !empty($payload['can_send_mail']);
            $hasMail = Sheet::userCanSendMail($user);
            if ($wantsMail && !$hasMail) {
                Sheet::grantMailPermission($user);
            } elseif (!$wantsMail && $hasMail) {
                Sheet::revokeMailPermission($user);
                // Если у юзера был подключён Gmail — не отзываем токены сами,
                // юзер сам отключит когда захочет. Право просто скрывает кнопки.
            }
        }

        return redirect()->route('users.index');
    }

    public function destroy(User $user)
    {
        if ((int) $user->id === (int) Auth::id()) {
            return back()->withErrors(['user' => 'Нельзя удалить самого себя.']);
        }
        $user->delete();
        return redirect()->route('users.index');
    }
}
