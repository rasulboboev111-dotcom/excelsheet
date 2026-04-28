<?php

namespace App\Http\Controllers;

use App\Models\User;
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
        $users = User::orderBy('id')->get(['id', 'name', 'email', 'created_at'])
            ->map(function ($u) {
                return [
                    'id'         => $u->id,
                    'name'       => $u->name,
                    'email'      => $u->email,
                    'is_admin'   => $u->hasRole('admin'),
                    'created_at' => $u->created_at?->toDateTimeString(),
                ];
            });

        return Inertia::render('Users/Index', [
            'users' => $users,
        ]);
    }

    public function store(Request $request)
    {
        $payload = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(6)],
            'is_admin' => 'sometimes|boolean',
        ]);

        $user = User::create([
            'name'              => $payload['name'],
            'email'             => $payload['email'],
            'password'          => Hash::make($payload['password']),
            'email_verified_at' => now(), // админ создаёт юзера → почта считается подтверждённой
        ]);

        if (!empty($payload['is_admin'])) {
            $user->assignRole('admin');
        }

        return redirect()->route('users.index');
    }

    public function update(Request $request, User $user)
    {
        $payload = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::min(6)],
            'is_admin' => 'sometimes|boolean',
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
        if ($wantsAdmin && !$user->hasRole('admin')) {
            $user->assignRole('admin');
        } elseif (!$wantsAdmin && $user->hasRole('admin') && !$isSelf) {
            $user->removeRole('admin');
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
