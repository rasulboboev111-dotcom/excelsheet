// Тест _formatValueForDiff из Dashboard.vue — копия логики 1:1.
// Запуск: node tests/format-diff-value.test.mjs

const _formatValueForDiff = (v) => {
    if (v === null || v === undefined || v === '') return '';
    if (v instanceof Date && !isNaN(v.getTime())) {
        const dd = String(v.getDate()).padStart(2, '0');
        const mm = String(v.getMonth() + 1).padStart(2, '0');
        return `${dd}.${mm}.${v.getFullYear()}`;
    }
    if (typeof v === 'object') {
        try { return JSON.stringify(v); } catch (_) { return '[object]'; }
    }
    if (typeof v === 'string') {
        if (v.startsWith('=')) return v;
        const isoMatch = /^(\d{4})-(\d{2})-(\d{2})(?:T.*)?$/.exec(v);
        if (isoMatch) return `${isoMatch[3]}.${isoMatch[2]}.${isoMatch[1]}`;
        const t = v.trim();
        if (/^-?\d+([.,]\d+)?$/.test(t)) {
            const num = parseFloat(t.replace(',', '.'));
            if (!isNaN(num)) return num.toLocaleString('ru-RU', { maximumFractionDigits: 10 });
        }
        return v;
    }
    if (typeof v === 'number' && isFinite(v)) {
        return v.toLocaleString('ru-RU', { maximumFractionDigits: 10 });
    }
    return String(v);
};

const cases = [
    // empty / null
    [null, ''],
    [undefined, ''],
    ['', ''],

    // Date instances
    [new Date(2026, 4, 12), '12.05.2026'],
    [new Date(2025, 0, 3), '03.01.2025'],

    // ISO strings
    ['2026-05-12T00:00:00.000Z', '12.05.2026'],
    ['2026-05-12', '12.05.2026'],
    ['2025-01-03T15:30:00', '03.01.2025'],

    // Numeric strings → locale
    ['1234.5', '1 234,5'],
    ['1234567', '1 234 567'],
    ['1234,5', '1 234,5'],
    ['-42', '-42'],

    // Numbers
    [1234567, '1 234 567'],
    [3.14, '3,14'],
    [0, '0'],

    // Formulas — passthrough
    ['=SUM(A1:A10)', '=SUM(A1:A10)'],
    ['=A1+B1', '=A1+B1'],

    // Plain text — passthrough
    ['Hello', 'Hello'],
    ['Не дата 2026', 'Не дата 2026'],

    // Object (style etc.)
    [{ bold: true }, '{"bold":true}'],
];

let pass = 0, fail = 0;
for (const [input, expected] of cases) {
    const actual = _formatValueForDiff(input);
    const ok = actual === expected;
    if (ok) {
        pass++;
        console.log(`PASS  in=${JSON.stringify(input)}  →  ${JSON.stringify(actual)}`);
    } else {
        fail++;
        console.error(`FAIL  in=${JSON.stringify(input)}  expected=${JSON.stringify(expected)}  got=${JSON.stringify(actual)}`);
    }
}
console.log(`\nИтог: ${pass} pass, ${fail} fail`);
if (fail > 0) process.exitCode = 1;
