<?php

namespace Tests\Feature;

use App\Models\Sheet;
use App\Models\SheetAuditLog;
use App\Models\SheetData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Требование: «все, кроме админов, обязаны указывать причину при ИЗМЕНЕНИИ
 * существующих данных. Чистые добавления (пустая ячейка → значение) комментария
 * не требуют.»
 *
 * Покрывает SheetController::updateData (POST /sheets/{sheet}/data).
 */
class SheetCommentRequiredTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $editor;
    private Sheet $sheet;

    protected function setUp(): void
    {
        parent::setUp();

        // Сбрасываем кэш Spatie между тестами — иначе старые role-id висят.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::factory()->create(['email_verified_at' => now()]);
        Sheet::makeUserAdmin($this->admin);

        $this->editor = User::factory()->create(['email_verified_at' => now()]);

        // Создаём лист от имени editor'а и выдаём ему явную editor-роль —
        // зеркалит логику importSheet/store после фикса owner-revocation.
        $this->sheet = Sheet::create([
            'name'    => 'Test sheet',
            'user_id' => $this->editor->id,
            'order'   => 1,
            'columns' => [
                ['field' => 'A', 'headerName' => 'A'],
                ['field' => 'B', 'headerName' => 'B'],
            ],
        ]);
        $this->sheet->setUserRole($this->editor->id, 'editor');

        // Заполняем лист одной строкой с непустыми значениями — это будет
        // «существующие данные», правка которых требует комментарий у не-админа.
        SheetData::create([
            'sheet_id'  => $this->sheet->id,
            'row_index' => 0,
            'row_data'  => ['A' => '5', 'B' => 'old'],
        ]);
    }

    public function test_admin_can_modify_existing_cell_without_comment(): void
    {
        $response = $this->actingAs($this->admin)->post(
            "/sheets/{$this->sheet->id}/data",
            ['rows' => [['row_index' => 0, 'data' => ['A' => '10', 'B' => 'old']]]]
        );

        $response->assertSessionHasNoErrors();

        $this->assertSame(
            '10',
            (string) SheetData::where('sheet_id', $this->sheet->id)
                ->where('row_index', 0)->first()->row_data['A']
        );
    }

    public function test_non_admin_cannot_modify_existing_cell_without_comment(): void
    {
        $response = $this->actingAs($this->editor)->post(
            "/sheets/{$this->sheet->id}/data",
            ['rows' => [['row_index' => 0, 'data' => ['A' => '10', 'B' => 'old']]]]
        );

        $response->assertSessionHasErrors('comment');

        // Главная инвариант: правка БЕЗ комментария НЕ записалась в БД.
        $this->assertSame(
            '5',
            (string) SheetData::where('sheet_id', $this->sheet->id)
                ->where('row_index', 0)->first()->row_data['A']
        );
    }

    public function test_non_admin_can_modify_existing_cell_with_comment(): void
    {
        $response = $this->actingAs($this->editor)->post(
            "/sheets/{$this->sheet->id}/data",
            [
                'rows'    => [['row_index' => 0, 'data' => ['A' => '10', 'B' => 'old']]],
                'comment' => 'Исправил опечатку',
            ]
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame(
            '10',
            (string) SheetData::where('sheet_id', $this->sheet->id)
                ->where('row_index', 0)->first()->row_data['A']
        );

        // Комментарий попал в журнал аудита.
        $log = SheetAuditLog::where('sheet_id', $this->sheet->id)
            ->where('action', 'cell_edit')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('Исправил опечатку', $log->details['comment'] ?? null);
    }

    public function test_non_admin_can_add_to_empty_cell_without_comment(): void
    {
        // row_index 1 не существует в БД → snap пустой → правка = ДОБАВЛЕНИЕ.
        $response = $this->actingAs($this->editor)->post(
            "/sheets/{$this->sheet->id}/data",
            ['rows' => [['row_index' => 1, 'data' => ['A' => 'fresh']]]]
        );

        $response->assertSessionHasNoErrors();

        $row = SheetData::where('sheet_id', $this->sheet->id)
            ->where('row_index', 1)->first();
        $this->assertNotNull($row);
        $this->assertSame('fresh', $row->row_data['A']);
    }

    public function test_non_admin_clearing_existing_cell_requires_comment(): void
    {
        // Очистка непустой ячейки = модификация → коммент обязателен.
        $response = $this->actingAs($this->editor)->post(
            "/sheets/{$this->sheet->id}/data",
            ['rows' => [['row_index' => 0, 'data' => ['A' => '', 'B' => 'old']]]]
        );

        $response->assertSessionHasErrors('comment');
        $this->assertSame(
            '5',
            (string) SheetData::where('sheet_id', $this->sheet->id)
                ->where('row_index', 0)->first()->row_data['A']
        );
    }

    public function test_modifying_single_cell_still_triggers_comment_requirement(): void
    {
        // Регрессия для жалобы пользователя «коменты должны работать даже
        // при изменении ОДНОЙ ячейки». Шлём минимальный payload c одной правкой.
        $response = $this->actingAs($this->editor)->post(
            "/sheets/{$this->sheet->id}/data",
            ['rows' => [['row_index' => 0, 'data' => ['A' => '999', 'B' => 'old']]]]
        );

        $response->assertSessionHasErrors('comment');
    }

    public function test_style_only_change_does_not_require_comment(): void
    {
        // Изменение только стилей (полей *_style) не считается value-change'ом.
        $response = $this->actingAs($this->editor)->post(
            "/sheets/{$this->sheet->id}/data",
            ['rows' => [['row_index' => 0, 'data' => [
                'A'        => '5',                    // не изменилось
                'B'        => 'old',                  // не изменилось
                'A_style'  => ['fontWeight' => 'bold'], // только стиль
            ]]]]
        );

        $response->assertSessionHasNoErrors();
    }
}
