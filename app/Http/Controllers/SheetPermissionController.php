<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\Access\AuthorizationException;

use App\Models\Sheet;
use App\Models\User;

class SheetPermissionController extends Controller
{
    private function authorizeAdmin(): void
    {
        $user = Auth::user();
        if (!$user || !$user->hasRole('admin')) {
            throw new AuthorizationException('Admin only.');
        }
    }

    public function index(Sheet $sheet)
    {
        $this->authorizeAdmin();

        return [
            // Не показываем админов в списке — у них и так полный доступ.
            'allUsers' => User::orderBy('name')
                ->get(['id', 'name', 'email'])
                ->reject(fn ($u) => $u->hasRole('admin'))
                ->values(),
            'assignedUsers' => $sheet->users()->get()->map(function($user) {
                return [
                    'id' => $user->id,
                    'role' => $user->pivot->role
                ];
            })
        ];
    }

    public function update(Request $request, Sheet $sheet)
    {
        $this->authorizeAdmin();

        $payload = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'role'    => 'required|string|in:none,viewer,editor',
        ]);

        // Не назначаем роль самому админу через этот эндпоинт — у него и так всё.
        $target = User::find($payload['user_id']);
        if ($target && $target->hasRole('admin')) {
            throw new AuthorizationException('Cannot assign sheet role to an admin (they already have full access).');
        }

        if ($payload['role'] === 'none') {
            $sheet->users()->detach($payload['user_id']);
        } else {
            $sheet->users()->syncWithoutDetaching([
                $payload['user_id'] => ['role' => $payload['role']],
            ]);
        }

        return back();
    }

    /**
     * Назначить роль ОДНОМУ юзеру сразу на НЕСКОЛЬКО листов.
     * Используется после импорта, когда админ хочет одним движением раздать
     * права на все только что созданные листы.
     */
    public function bulk(Request $request)
    {
        $this->authorizeAdmin();

        $payload = $request->validate([
            'sheet_ids'   => 'required|array|min:1|max:1000',
            'sheet_ids.*' => 'required|integer|exists:sheets,id',
            'user_id'     => 'required|integer|exists:users,id',
            'role'        => 'required|string|in:none,viewer,editor',
        ]);

        $target = User::find($payload['user_id']);
        if ($target && $target->hasRole('admin')) {
            throw new AuthorizationException('Cannot assign sheet role to an admin.');
        }

        $sheets = Sheet::whereIn('id', $payload['sheet_ids'])->get();
        foreach ($sheets as $sheet) {
            if ($payload['role'] === 'none') {
                $sheet->users()->detach($payload['user_id']);
            } else {
                $sheet->users()->syncWithoutDetaching([
                    $payload['user_id'] => ['role' => $payload['role']],
                ]);
            }
        }

        return response()->json(['updated' => $sheets->count()]);
    }
}
