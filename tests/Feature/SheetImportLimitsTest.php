<?php

namespace Tests\Feature;

use App\Models\Sheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Лимиты на POST /sheets/import-sheet — защита от DoS через гигантский payload.
 * См. SheetController::importSheet (Content-Length / total-cells / per-cell-length).
 */
class SheetImportLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user = User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_normal_import_succeeds(): void
    {
        $response = $this->actingAs($this->user)->postJson('/sheets/import-sheet', [
            'name'    => 'Small',
            'columns' => [['field' => 'A', 'headerName' => 'A']],
            'rows'    => [
                ['row_index' => 0, 'data' => ['A' => 'hello']],
                ['row_index' => 1, 'data' => ['A' => 'world']],
            ],
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('id'));
    }

    public function test_import_rejects_cell_value_exceeding_max_length(): void
    {
        // MAX_CELL_VALUE_LENGTH = 32 767. Шлём строку на 1 длиннее.
        $tooLong = str_repeat('x', 32_768);

        $response = $this->actingAs($this->user)->postJson('/sheets/import-sheet', [
            'name'    => 'Too long cell',
            'columns' => [['field' => 'A', 'headerName' => 'A']],
            'rows'    => [
                ['row_index' => 0, 'data' => ['A' => $tooLong]],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('rows');
        $this->assertSame(0, Sheet::count());
    }

    public function test_import_accepts_cell_at_max_length_boundary(): void
    {
        // Ровно на лимите — должно пройти.
        $atLimit = str_repeat('x', 32_767);

        $response = $this->actingAs($this->user)->postJson('/sheets/import-sheet', [
            'name'    => 'Boundary',
            'columns' => [['field' => 'A', 'headerName' => 'A']],
            'rows'    => [
                ['row_index' => 0, 'data' => ['A' => $atLimit]],
            ],
        ]);

        $response->assertOk();
    }

    public function test_import_rejects_payload_with_too_many_rows(): void
    {
        // MAX_IMPORT_ROWS = 100 000. Шлём 100 001 строк → должно завалиться валидацией.
        // Поднимаем memory_limit временно — большой JSON не помещается в дефолтные 128M.
        $prevMem = ini_set('memory_limit', '512M');
        try {
            $rows = [];
            for ($i = 0; $i <= 100_000; $i++) {
                $rows[] = ['row_index' => $i, 'data' => []];
            }

            $response = $this->actingAs($this->user)->postJson('/sheets/import-sheet', [
                'name' => 'Too many rows',
                'rows' => $rows,
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors('rows');
        } finally {
            ini_set('memory_limit', $prevMem);
        }
    }

    public function test_import_rejects_total_cell_count_over_limit(): void
    {
        // MAX_IMPORT_TOTAL_CELLS = 1 000 000. Делаем 2 000 строк × 600 колонок = 1.2M ячеек.
        // Это пройдёт rows.max и data.max лимиты, но провалится на суммарном чеке.
        // Большой payload требует больше памяти, чем дефолтные 128M PHP CLI.
        $prevMem = ini_set('memory_limit', '768M');
        try {
            $rowData = [];
            for ($c = 0; $c < 600; $c++) {
                $rowData['c' . $c] = 'x';
            }
            $rows = [];
            for ($r = 0; $r < 2000; $r++) {
                $rows[] = ['row_index' => $r, 'data' => $rowData];
            }

            $response = $this->actingAs($this->user)->postJson('/sheets/import-sheet', [
                'name' => 'Over total cells',
                'rows' => $rows,
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors('rows');
            $this->assertSame(0, Sheet::count());
        } finally {
            ini_set('memory_limit', $prevMem);
        }
    }

    public function test_import_rejects_oversized_content_length_with_413(): void
    {
        // Эмулируем реальный размер запроса через ручной заголовок.
        // MAX_IMPORT_BODY_BYTES = 50 МБ → шлём с Content-Length чуть больше.
        $oversize = (50 * 1024 * 1024) + 1;

        $response = $this->actingAs($this->user)
            ->withHeaders(['Content-Length' => (string) $oversize])
            ->postJson('/sheets/import-sheet', [
                'name' => 'Fake oversize',
                'rows' => [],
            ]);

        $response->assertStatus(413);
    }

    public function test_unauthenticated_user_cannot_import(): void
    {
        $response = $this->postJson('/sheets/import-sheet', [
            'name' => 'Anonymous attempt',
            'rows' => [],
        ]);

        $response->assertStatus(401);
    }
}
