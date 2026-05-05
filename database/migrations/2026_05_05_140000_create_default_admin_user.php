<?php

use App\Models\Sheet;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;

/**
 * Создаёт первого админа из переменных окружения ADMIN_EMAIL / ADMIN_PASSWORD.
 * Идемпотентна: если юзер с таким email уже есть — только проставляет admin-роль.
 *
 * Зачем через env, а не хардкодом: пароль не попадает в git и в публичные
 * dev-окружения. Если env-переменных нет — миграция тихо пропускается, чтобы
 * на CI/тестах не создавалась учётка с известным паролем.
 *
 * Пример .env:
 *   ADMIN_EMAIL=admin@example.com
 *   ADMIN_PASSWORD=YourStrongPassword123
 *   ADMIN_NAME=Главный админ
 */
return new class extends Migration
{
    public function up(): void
    {
        $email    = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');
        $name     = env('ADMIN_NAME', 'Admin');

        if (!$email || !$password) {
            // Тихо пропускаем — не создаём захардкоженную учётку.
            return;
        }

        $user = User::where('email', $email)->first();

        if (!$user) {
            $user = User::create([
                'name'              => $name,
                'email'             => $email,
                'password'          => Hash::make($password),
                'email_verified_at' => now(),
            ]);
        }

        // Назначаем глобальную admin-роль (team_id = NULL).
        // Если уже есть — makeUserAdmin внутри ничего не делает.
        Sheet::makeUserAdmin($user);
    }

    public function down(): void
    {
        $email = env('ADMIN_EMAIL');
        if (!$email) return;

        $user = User::where('email', $email)->first();
        if ($user) {
            // Снимаем только admin-роль. Сам аккаунт оставляем —
            // rollback миграции не должен уничтожать пользователя
            // (в нём могут висеть листы и foreign key'и).
            Sheet::removeUserAdmin($user);
        }
    }
};
