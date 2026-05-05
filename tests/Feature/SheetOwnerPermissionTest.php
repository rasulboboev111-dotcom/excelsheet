<?php

namespace Tests\Feature;

use App\Models\Sheet;
use App\Models\SheetData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Требование: «admin может отозвать у owner'а право редактирования и доступа».
 *
 * До фикса — Sheet::canEdit/canView имели хардкод `if (isOwnedBy) return true`,
 * и admin'ская выдача 'none' через permissions UI на owner'а не действовала.
 * После фикса owner проходит через тот же per-sheet role-check, что и все.
 */
class SheetOwnerPermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::factory()->create(['email_verified_at' => now()]);
        Sheet::makeUserAdmin($this->admin);

        $this->owner = User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_owner_with_editor_role_can_edit(): void
    {
        $sheet = $this->createSheetOwnedBy($this->owner);
        $sheet->setUserRole($this->owner->id, 'editor');

        $this->assertTrue($sheet->canEdit($this->owner->id));
        $this->assertTrue($sheet->canView($this->owner->id));
    }

    public function test_owner_without_role_cannot_edit_or_view(): void
    {
        // Лист создан, но editor-роль НЕ выдана. Зеркалит ситуацию,
        // когда admin вызвал setUserRole($ownerId, 'none').
        $sheet = $this->createSheetOwnedBy($this->owner);

        // Сбрасываем кэш Spatie, иначе предыдущие assignment'ы могут засветиться.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($sheet->canEdit($this->owner->id));
        $this->assertFalse($sheet->canView($this->owner->id));
    }

    public function test_admin_revoking_owner_role_blocks_edit(): void
    {
        $sheet = $this->createSheetOwnedBy($this->owner);
        $sheet->setUserRole($this->owner->id, 'editor');
        $this->assertTrue($sheet->canEdit($this->owner->id));

        // Admin меняет роль на 'none' — owner теряет доступ.
        $sheet->setUserRole($this->owner->id, 'none');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($sheet->canEdit($this->owner->id));
        $this->assertFalse($sheet->canView($this->owner->id));
    }

    public function test_admin_demoting_owner_to_viewer_allows_view_blocks_edit(): void
    {
        $sheet = $this->createSheetOwnedBy($this->owner);
        $sheet->setUserRole($this->owner->id, 'viewer');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($sheet->canEdit($this->owner->id));
        $this->assertTrue($sheet->canView($this->owner->id));
    }

    public function test_admin_can_edit_any_sheet_regardless_of_per_sheet_role(): void
    {
        // У админа НЕТ per-sheet роли на чужой лист — но через global admin
        // role canEdit всё равно возвращает true.
        $sheet = $this->createSheetOwnedBy($this->owner);

        $this->assertTrue($sheet->canEdit($this->admin->id));
        $this->assertTrue($sheet->canView($this->admin->id));
    }

    public function test_revoked_owner_gets_403_on_updateData_endpoint(): void
    {
        $sheet = $this->createSheetOwnedBy($this->owner);
        $sheet->setUserRole($this->owner->id, 'none');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $response = $this->actingAs($this->owner)->post(
            "/sheets/{$sheet->id}/data",
            ['rows' => [['row_index' => 0, 'data' => ['A' => 'X']]]]
        );

        $response->assertForbidden();
    }

    public function test_import_sheet_assigns_editor_role_to_non_admin_owner(): void
    {
        // POST /sheets/import-sheet от имени не-админа должен авто-выдать
        // editor роль импортёру (иначе он сам не сможет редактировать свой лист).
        $importer = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($importer)->postJson(
            '/sheets/import-sheet',
            [
                'name'    => 'Imported',
                'columns' => [['field' => 'A', 'headerName' => 'A']],
                'rows'    => [['row_index' => 0, 'data' => ['A' => '1']]],
            ]
        );

        $response->assertOk();
        $sheetId = $response->json('id');
        $sheet = Sheet::find($sheetId);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($sheet->canEdit($importer->id));
    }

    private function createSheetOwnedBy(User $user): Sheet
    {
        return Sheet::create([
            'name'    => 'Owned by ' . $user->name,
            'user_id' => $user->id,
            'order'   => 1,
            'columns' => [['field' => 'A', 'headerName' => 'A']],
        ]);
    }
}
