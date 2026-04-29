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
        if (!$user || !Sheet::userIsAdmin($user)) {
            throw new AuthorizationException('Admin only.');
        }
    }

    public function index(Sheet $sheet)
    {
        $this->authorizeAdmin();

        // Не показываем админов в списке — у них и так полный доступ.
        $allUsers = User::orderBy('name')
            ->get(['id', 'name', 'email'])
            ->reject(fn (User $u) => Sheet::userIsAdmin($u))
            ->values();

        $assignedUsers = $sheet->assignedUsers()->map(fn ($r) => [
            'id'   => (int) $r->id,
            'role' => $r->role,
        ]);

        return [
            'allUsers'      => $allUsers,
            'assignedUsers' => $assignedUsers,
        ];
    }

    public function update(Request $request, Sheet $sheet)
    {
        $this->authorizeAdmin();

        $payload = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'role'    => 'required|string|in:none,viewer,editor',
        ]);

        // Не назначаем роль самому админу — у него и так всё.
        $target = User::find($payload['user_id']);
        if ($target && Sheet::userIsAdmin($target)) {
            throw new AuthorizationException('Cannot assign sheet role to an admin (they already have full access).');
        }

        $sheet->setUserRole((int) $payload['user_id'], $payload['role']);

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
        if ($target && Sheet::userIsAdmin($target)) {
            throw new AuthorizationException('Cannot assign sheet role to an admin.');
        }

        $sheets = Sheet::whereIn('id', $payload['sheet_ids'])->get();
        foreach ($sheets as $sheet) {
            $sheet->setUserRole((int) $payload['user_id'], $payload['role']);
        }

        return response()->json(['updated' => $sheets->count()]);
    }
}
