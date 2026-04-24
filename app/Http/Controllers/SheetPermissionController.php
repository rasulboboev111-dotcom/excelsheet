<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Sheet;
use App\Models\User;

class SheetPermissionController extends Controller
{
    public function index(Sheet $sheet)
    {
        return [
            'allUsers' => User::all(['id', 'name', 'email']),
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
        $userId = $request->input('user_id');
        $role = $request->input('role'); // editor, viewer, none

        if ($role === 'none') {
            $sheet->users()->detach($userId);
        } else {
            $sheet->users()->syncWithoutDetaching([$userId => ['role' => $role]]);
        }

        return back();
    }
}
