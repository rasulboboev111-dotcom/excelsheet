// Регрессионный тест для баг-фикса в Dashboard.vue:
// "Misalignment имён и ID листов при частичном фейле импорта".
//
// До фикса: created хранил только ID, а sheetNames строились из
//   wb.sheets.slice(0, created.length).map(s => s.name)
// При фейле НЕ В КОНЦЕ массивы расходились.
//
// После фикса: created хранит {id, name}-пары → расхождение невозможно.
//
// Запуск: node tests/import-misalignment.test.mjs
// Все 4 сценария должны напечатать PASS.

const assert = (cond, msg) => {
    if (!cond) throw new Error('ASSERTION FAILED: ' + msg);
};

const arraysEqual = (a, b) => a.length === b.length && a.every((x, i) => x === b[i]);

// Изолированная копия логики handleFileChosen — только часть с циклом
// импорта и сборкой bulkPermissions. Axios мокается через mockPost.
async function runImport(wbSheets, mockPost) {
    const sleep = (ms) => new Promise(r => setTimeout(r, ms));
    const created = []; // [{id, name}]
    let failed = 0;

    for (let i = 0; i < wbSheets.length; i++) {
        const s = wbSheets[i];
        const body = {
            name: s.name || `Лист ${i + 1}`,
            columns: s.columnDefs || [],
            rows: (s.rowData || []).map((r, j) => ({ row_index: j, data: r }))
        };
        let newId = null;
        for (let attempt = 0; attempt < 3 && newId == null; attempt++) {
            try {
                const resp = await mockPost(i, body);
                newId = resp?.data?.id ?? null;
            } catch (err) {
                const status = err?.response?.status;
                if (status === 429 && attempt < 2) {
                    const retryAfter = parseInt(err.response.headers?.['retry-after'] || '5', 10);
                    const waitMs = Math.min(60_000, Math.max(1000, retryAfter * 1000));
                    await sleep(0); // в тесте не ждём реально
                    void waitMs;
                    continue;
                }
                break;
            }
        }
        if (newId) {
            created.push({ id: newId, name: body.name });
        } else {
            failed++;
        }
    }

    return {
        sheetIds: created.map(c => c.id),
        sheetNames: created.map(c => c.name),
        failed,
        createdCount: created.length,
    };
}

const sheets3 = [
    { name: 'Финансы' },
    { name: 'Контрагенты' },
    { name: 'Договоры' },
];

let testN = 0;
const run = async (label, fn) => {
    testN++;
    try {
        await fn();
        console.log(`[${testN}] PASS — ${label}`);
    } catch (e) {
        console.error(`[${testN}] FAIL — ${label}: ${e.message}`);
        process.exitCode = 1;
    }
};

// 1. Все три листа создаются успешно
await run('all succeed → ids/names совпадают 1:1', async () => {
    const result = await runImport(sheets3, async (i) => ({ data: { id: 100 + i } }));
    assert(arraysEqual(result.sheetIds, [100, 101, 102]), `ids: ${result.sheetIds}`);
    assert(arraysEqual(result.sheetNames, ['Финансы', 'Контрагенты', 'Договоры']),
        `names: ${result.sheetNames}`);
    assert(result.failed === 0, 'failed should be 0');
});

// 2. КЛЮЧЕВОЙ СЦЕНАРИЙ — упал второй лист (середина)
//    До фикса: ids=[100,102], names=["Финансы","Контрагенты"] → Договоры подписаны как Контрагенты
//    После: ids=[100,102], names=["Финансы","Договоры"] ✓
await run('middle fail → имена совпадают с ID', async () => {
    const result = await runImport(sheets3, async (i) => {
        if (i === 1) throw { response: { status: 500 } };
        return { data: { id: 100 + i } };
    });
    assert(arraysEqual(result.sheetIds, [100, 102]),
        `ids should be [100, 102], got ${JSON.stringify(result.sheetIds)}`);
    assert(arraysEqual(result.sheetNames, ['Финансы', 'Договоры']),
        `names should be [Финансы, Договоры], got ${JSON.stringify(result.sheetNames)}`);
    assert(result.failed === 1, 'failed should be 1');
});

// 3. Упал первый лист
await run('first fail → пропуск первой пары', async () => {
    const result = await runImport(sheets3, async (i) => {
        if (i === 0) throw { response: { status: 500 } };
        return { data: { id: 100 + i } };
    });
    assert(arraysEqual(result.sheetIds, [101, 102]), `ids: ${result.sheetIds}`);
    assert(arraysEqual(result.sheetNames, ['Контрагенты', 'Договоры']),
        `names: ${result.sheetNames}`);
});

// 4. Упал последний лист — раньше случайно совпадало, теперь явно корректно
await run('last fail → две первые пары', async () => {
    const result = await runImport(sheets3, async (i) => {
        if (i === 2) throw { response: { status: 500 } };
        return { data: { id: 100 + i } };
    });
    assert(arraysEqual(result.sheetIds, [100, 101]), `ids: ${result.sheetIds}`);
    assert(arraysEqual(result.sheetNames, ['Финансы', 'Контрагенты']),
        `names: ${result.sheetNames}`);
});

// 5. Все три упали
await run('all fail → пустые массивы', async () => {
    const result = await runImport(sheets3, async () => {
        throw { response: { status: 500 } };
    });
    assert(result.sheetIds.length === 0, 'ids empty');
    assert(result.sheetNames.length === 0, 'names empty');
    assert(result.failed === 3, 'failed=3');
});

// 6. Retry на 429 — после двух неудач третья попытка проходит
await run('429 retry → лист всё же создаётся', async () => {
    const attempts = {};
    const result = await runImport([sheets3[0]], async (i) => {
        attempts[i] = (attempts[i] || 0) + 1;
        if (attempts[i] < 3) throw { response: { status: 429, headers: { 'retry-after': '0' } } };
        return { data: { id: 999 } };
    });
    assert(arraysEqual(result.sheetIds, [999]), `ids: ${result.sheetIds}`);
    assert(arraysEqual(result.sheetNames, ['Финансы']), `names: ${result.sheetNames}`);
    assert(result.failed === 0, 'failed=0');
});

if (process.exitCode === 1) {
    console.error('\nИтог: ЕСТЬ ПРОВАЛЫ');
} else {
    console.log('\nИтог: все 6 тестов PASS');
}
