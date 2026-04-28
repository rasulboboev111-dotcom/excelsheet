// ExcelJS и file-saver вытаскиваются динамически — нужны только при чтении/записи .xlsx.
// При обычной навигации сайта эти ~500 КБ не грузятся.
let _ExcelJS = null;
let _saveAs = null;
const ensureExcelJS = async () => {
    if (!_ExcelJS) {
        const mod = await import('exceljs');
        _ExcelJS = mod.default || mod;
    }
    return _ExcelJS;
};
const ensureSaveAs = async () => {
    if (!_saveAs) {
        const mod = await import('file-saver');
        _saveAs = mod.saveAs || mod.default?.saveAs || mod.default;
    }
    return _saveAs;
};

// Excel column index (1-based) → letter (A, B, ..., Z, AA, AB, ...)
export const colNumberToLetter = (n) => {
    let s = '';
    while (n > 0) {
        const m = (n - 1) % 26;
        s = String.fromCharCode(65 + m) + s;
        n = Math.floor((n - 1) / 26);
    }
    return s || 'A';
};
export const colLetterToNumber = (letter) => {
    let n = 0;
    for (const ch of letter.toUpperCase()) {
        if (ch < 'A' || ch > 'Z') break;
        n = n * 26 + (ch.charCodeAt(0) - 64);
    }
    return n;
};

const argbToHex = (argb) => {
    if (!argb) return null;
    const s = String(argb);
    if (s.length === 8) return '#' + s.slice(2);
    if (s.length === 6) return '#' + s;
    return null;
};

const exceljsAlignmentToCss = (a) => {
    if (!a) return {};
    const out = {};
    if (a.horizontal) out.textAlign = a.horizontal;
    if (a.vertical) out.verticalAlign = a.vertical === 'middle' ? 'middle' : a.vertical;
    if (a.wrapText) out.whiteSpace = 'normal';
    return out;
};
const exceljsBorderToCss = (b) => {
    if (!b) return {};
    const out = {};
    const map = (side) => {
        if (!side) return null;
        const w = side.style === 'thick' ? 3 : (side.style === 'medium' ? 2 : 1);
        const sty = side.style === 'double' ? 'double' : (side.style === 'dotted' ? 'dotted' : (side.style === 'dashed' ? 'dashed' : 'solid'));
        const c = (side.color && argbToHex(side.color.argb)) || '#000';
        return `${w}px ${sty} ${c}`;
    };
    const t = map(b.top), bo = map(b.bottom), l = map(b.left), r = map(b.right);
    if (t) out.borderTop = t;
    if (bo) out.borderBottom = bo;
    if (l) out.borderLeft = l;
    if (r) out.borderRight = r;
    return out;
};

// Parse exceljs cell.style into our internal _style
const cellStyleFromExcel = (cell) => {
    const out = {};
    const f = cell.font;
    if (f) {
        if (f.bold) out.fontWeight = 'bold';
        if (f.italic) out.fontStyle = 'italic';
        if (f.underline) out.textDecoration = 'underline';
        if (f.size) out.fontSize = f.size + 'px';
        if (f.name) out.fontFamily = f.name;
        if (f.color && f.color.argb) out.color = argbToHex(f.color.argb);
    }
    const fill = cell.fill;
    if (fill && fill.type === 'pattern' && fill.pattern === 'solid') {
        const fg = fill.fgColor && fill.fgColor.argb;
        if (fg) out.backgroundColor = argbToHex(fg);
    }
    Object.assign(out, exceljsAlignmentToCss(cell.alignment));
    Object.assign(out, exceljsBorderToCss(cell.border));
    if (cell.numFmt) out.numFmt = cell.numFmt;
    return Object.keys(out).length > 0 ? out : null;
};

// Excel-serial ↔ JS Date с коррекцией «фантомного 29 февраля 1900».
// Excel ошибочно считает 1900-й високосным; для serial < 60 (<= 1900-02-28) реальная
// дата на 1 день ПОЗЖЕ относительно «честного» расчёта от 1900-01-01.
// Формулы ниже поправлены на этот сдвиг.
export const excelSerialToDate = (serial) => {
    if (typeof serial !== 'number' || !isFinite(serial)) return null;
    // serial >= 60 — после фантомного дня. serial < 60 — нужно сдвинуть на +1.
    const adjusted = serial < 60 ? serial : serial - 1;
    // 25568 = дней от 1900-01-01 до 1970-01-01 (без фантомного дня).
    const utcDays = adjusted - 25568;
    const ms = utcDays * 86400 * 1000;
    return new Date(ms);
};
const dateToExcelSerial = (date) => {
    const days = date.getTime() / (86400 * 1000) + 25568;
    return days < 60 ? days : days + 1;
};

// Read .xlsx File → workbook structure { sheets: [{ name, hidden, columnDefs, rowData, merges, validations, colWidths, rowHeights }] }
export async function readXlsxFile(file) {
    const arrayBuffer = await file.arrayBuffer();
    const ExcelJS = await ensureExcelJS();
    const wb = new ExcelJS.Workbook();
    await wb.xlsx.load(arrayBuffer);

    const sheets = [];
    wb.eachSheet((ws) => {
        const sheet = {
            name: ws.name,
            hidden: ws.state === 'hidden' || ws.state === 'veryHidden',
            columnDefs: [],
            rowData: [],
            merges: [],
            validations: {},
            colWidths: {},
            rowHeights: {}
        };

        const maxCol = ws.actualColumnCount || ws.columnCount || 1;
        const maxRow = ws.actualRowCount || ws.rowCount || 1;

        // Build column defs (A, B, C, ...)
        for (let c = 1; c <= maxCol; c++) {
            const letter = colNumberToLetter(c);
            sheet.columnDefs.push({ field: letter, headerName: letter });
            const col = ws.getColumn(c);
            if (col && col.width) sheet.colWidths[letter] = Math.round(col.width * 7);
        }

        // Read rows
        for (let r = 1; r <= maxRow; r++) {
            const row = ws.getRow(r);
            const rowObj = {};
            for (let c = 1; c <= maxCol; c++) {
                const letter = colNumberToLetter(c);
                const cell = row.getCell(c);
                let value = cell.value;
                if (value instanceof Date) {
                    rowObj[letter] = dateToExcelSerial(value);
                } else if (value && typeof value === 'object') {
                    // ВАЖНО: только value.formula (мастер shared range) содержит текст формулы.
                    // value.sharedFormula у sharing-ячеек = координата мастера, а не формула —
                    // поэтому такие ячейки должны провалиться в ветку value.result и сохранить
                    // готовое числовое значение, иначе HyperFormula выдаст #VALUE!.
                    if (value.formula) {
                        rowObj[letter] = '=' + value.formula;
                    } else if (value.error) {
                        rowObj[letter] = value.error; // напр. "#REF!", "#DIV/0!"
                    } else if (Array.isArray(value.richText)) {
                        rowObj[letter] = value.richText.map(t => (t && t.text) ? t.text : '').join('');
                    } else if (value.hyperlink && (value.text || value.hyperlink)) {
                        rowObj[letter] = value.text ?? value.hyperlink;
                    } else if (value.result !== undefined && value.result !== null) {
                        rowObj[letter] = (value.result instanceof Date)
                            ? dateToExcelSerial(value.result)
                            : value.result;
                    } else if (value.text !== undefined && value.text !== null) {
                        rowObj[letter] = value.text;
                    }
                    // Неизвестные структуры просто пропускаем — лучше пустая ячейка,
                    // чем "[object Object]".
                } else if (value !== null && value !== undefined) {
                    rowObj[letter] = value;
                }
                const st = cellStyleFromExcel(cell);
                if (st) {
                    // Convert numFmt to our internal numberFormat
                    if (st.numFmt) {
                        const nf = String(st.numFmt).toLowerCase();
                        if (/[ymd]/.test(nf)) st.numberFormat = 'shortDate';
                        else if (nf.includes('%')) st.numberFormat = 'percent';
                        else if (nf.includes('$') || nf.includes('₽') || nf.includes('eur')) st.numberFormat = 'currency';
                        else if (nf.includes(',') || nf.includes('#,##')) st.numberFormat = 'comma';
                        delete st.numFmt;
                    }
                    rowObj[letter + '_style'] = st;
                }
            }
            if (row.height) sheet.rowHeights[r - 1] = Math.round(row.height * 1.33);
            sheet.rowData.push(rowObj);
        }

        // Merges
        if (ws.model && Array.isArray(ws.model.merges)) {
            ws.model.merges.forEach(rangeStr => {
                // e.g. "A1:O1"
                const m = /^([A-Z]+)(\d+):([A-Z]+)(\d+)$/.exec(rangeStr);
                if (!m) return;
                const c1 = colLetterToNumber(m[1]) - 1;
                const r1 = parseInt(m[2]) - 1;
                const c2 = colLetterToNumber(m[3]) - 1;
                const r2 = parseInt(m[4]) - 1;
                sheet.merges.push({
                    row: Math.min(r1, r2),
                    col: Math.min(c1, c2),
                    rowSpan: Math.abs(r2 - r1) + 1,
                    colSpan: Math.abs(c2 - c1) + 1
                });
            });
        }

        // Data validations
        if (ws.dataValidations && ws.dataValidations.model) {
            Object.entries(ws.dataValidations.model).forEach(([range, dv]) => {
                if (dv && dv.type === 'list' && dv.formulae && dv.formulae[0]) {
                    let raw = dv.formulae[0];
                    if (raw.startsWith('"') && raw.endsWith('"')) raw = raw.slice(1, -1);
                    const list = raw.split(/[,;]/).map(s => s.trim()).filter(Boolean);
                    // Range may be "B2:B17" — apply to whole column letter
                    const colMatch = /^([A-Z]+)\d+(:([A-Z]+)\d+)?$/.exec(range);
                    if (colMatch) {
                        sheet.validations[colMatch[1]] = list;
                    }
                }
            });
        }

        sheets.push(sheet);
    });

    return { sheets };
}

const cssToArgb = (css) => {
    if (!css) return null;
    let s = String(css).trim();
    if (s.startsWith('#')) s = s.slice(1);
    if (s.length === 3) s = s.split('').map(c => c + c).join('');
    if (s.length === 6) return 'FF' + s.toUpperCase();
    if (s.length === 8) return s.toUpperCase();
    return null;
};

const styleToExceljs = (style, cell) => {
    if (!style) return;
    const font = {};
    if (style.fontWeight === 'bold') font.bold = true;
    if (style.fontStyle === 'italic') font.italic = true;
    if (style.textDecoration === 'underline') font.underline = true;
    if (style.fontSize) font.size = parseInt(String(style.fontSize).replace('px', '')) || 11;
    if (style.fontFamily) font.name = style.fontFamily;
    if (style.color) {
        const argb = cssToArgb(style.color);
        if (argb) font.color = { argb };
    }
    if (Object.keys(font).length) cell.font = font;

    if (style.backgroundColor && style.backgroundColor !== 'transparent') {
        const argb = cssToArgb(style.backgroundColor);
        if (argb) cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb }, bgColor: { argb } };
    }

    const align = {};
    if (style.textAlign) align.horizontal = style.textAlign;
    if (style.verticalAlign) align.vertical = style.verticalAlign === 'middle' ? 'middle' : style.verticalAlign;
    if (style.whiteSpace === 'normal') align.wrapText = true;
    if (Object.keys(align).length) cell.alignment = align;

    const parseSide = (str) => {
        if (!str) return null;
        // e.g. "1px solid #000", "2px solid #000", "3px double #000"
        const m = /^(\d+)(?:px)?\s+([a-z]+)\s+(.+)$/i.exec(String(str));
        if (!m) return null;
        const w = parseInt(m[1]);
        const sty = m[2].toLowerCase();
        const argb = cssToArgb(m[3]) || 'FF000000';
        let style = 'thin';
        if (sty === 'double') style = 'double';
        else if (sty === 'dotted') style = 'dotted';
        else if (sty === 'dashed') style = 'dashed';
        else if (w >= 3) style = 'thick';
        else if (w === 2) style = 'medium';
        return { style, color: { argb } };
    };
    const border = {};
    const fromShort = parseSide(style.border);
    if (fromShort) { border.top = fromShort; border.bottom = fromShort; border.left = fromShort; border.right = fromShort; }
    if (style.borderTop) border.top = parseSide(style.borderTop);
    if (style.borderBottom) border.bottom = parseSide(style.borderBottom);
    if (style.borderLeft) border.left = parseSide(style.borderLeft);
    if (style.borderRight) border.right = parseSide(style.borderRight);
    if (Object.keys(border).length) cell.border = border;

    if (style.numberFormat === 'shortDate') cell.numFmt = 'dd.mm.yyyy';
    else if (style.numberFormat === 'percent') cell.numFmt = '0%';
    else if (style.numberFormat === 'currency') cell.numFmt = '$#,##0.00';
    else if (style.numberFormat === 'comma') cell.numFmt = '#,##0';
};

// Excel-ограничения для имени листа: длина ≤ 31, нельзя символы : \ / ? * [ ],
// нельзя начинать/заканчивать апострофом, нельзя дубликаты в одной книге (case-insensitive).
const sanitizeSheetName = (raw) => {
    let name = String(raw || 'Sheet').replace(/[:\\/?*\[\]]/g, '_').trim();
    name = name.replace(/^'+|'+$/g, ''); // убираем ведущие/конечные апострофы
    if (!name) name = 'Sheet';
    if (name.length > 31) name = name.slice(0, 31);
    return name;
};
const dedupSheetName = (desired, used) => {
    const base = sanitizeSheetName(desired);
    let candidate = base;
    let n = 2;
    // ExcelJS сравнивает имена case-insensitive; делаем то же самое.
    while (used.has(candidate.toLowerCase())) {
        // Сохраняем общую длину ≤ 31, ужимая base под суффикс ` (n)`.
        const suffix = ' (' + n + ')';
        const trimmed = base.length + suffix.length > 31
            ? base.slice(0, 31 - suffix.length)
            : base;
        candidate = trimmed + suffix;
        n++;
        if (n > 9999) break; // защита от бесконечного цикла
    }
    used.add(candidate.toLowerCase());
    return candidate;
};

// Build & download .xlsx from sheets array { name, columnDefs, rowData, merges, validations, colWidths, rowHeights, hidden }
export async function writeXlsxFile(filename, sheets) {
    const ExcelJS = await ensureExcelJS();
    const wb = new ExcelJS.Workbook();
    wb.creator = 'Excel Online';
    wb.created = new Date();

    const usedNames = new Set();
    sheets.forEach(sheet => {
        const safeName = dedupSheetName(sheet.name, usedNames);
        const ws = wb.addWorksheet(safeName, {
            state: sheet.hidden ? 'hidden' : 'visible'
        });

        const colDefs = sheet.columnDefs || [];
        const rows = sheet.rowData || [];

        // Rows — пишем напрямую через getRow(N), не используем ws.columns,
        // чтобы исключить любые «фантомные» строки от автогенерации шапки exceljs.
        rows.forEach((rowObj, ri) => {
            const r = ws.getRow(ri + 1);
            colDefs.forEach((cd, ci) => {
                const cell = r.getCell(ci + 1);
                const raw = rowObj[cd.field];
                if (raw === null || raw === undefined || raw === '') {
                    cell.value = null;
                } else if (typeof raw === 'string' && raw.startsWith('=')) {
                    cell.value = { formula: raw.slice(1) };
                } else if (typeof raw === 'number' && rowObj[cd.field + '_style']?.numberFormat === 'shortDate') {
                    cell.value = excelSerialToDate(raw);
                } else if (typeof raw === 'number') {
                    cell.value = raw;
                } else if (typeof raw === 'string') {
                    const trimmed = raw.trim();
                    if (trimmed !== '' && /^-?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/.test(trimmed)) {
                        const num = Number(trimmed);
                        cell.value = isFinite(num) ? num : raw;
                    } else {
                        cell.value = raw;
                    }
                } else {
                    cell.value = raw;
                }
                styleToExceljs(rowObj[cd.field + '_style'], cell);
            });
            if (sheet.rowHeights?.[ri]) r.height = sheet.rowHeights[ri] / 1.33;
            r.commit();
        });

        // Column widths — отдельно, после записи строк (не задействует header).
        colDefs.forEach((cd, ci) => {
            const w = sheet.colWidths?.[cd.field];
            const col = ws.getColumn(ci + 1);
            col.width = w ? Math.max(8, w / 7) : 12;
        });

        // Merges
        (sheet.merges || []).forEach(m => {
            const r1 = m.row + 1;
            const c1 = m.col + 1;
            const r2 = m.row + m.rowSpan;
            const c2 = m.col + m.colSpan;
            try { ws.mergeCells(r1, c1, r2, c2); } catch (e) {}
        });

        // Data validations — теперь данные с row 1, а не с row 2.
        Object.entries(sheet.validations || {}).forEach(([field, list]) => {
            if (!Array.isArray(list) || list.length === 0) return;
            const colIdx = colDefs.findIndex(c => c.field === field);
            if (colIdx === -1) return;
            const colLetter = colNumberToLetter(colIdx + 1);
            const lastRow = rows.length || 1;
            const formula = '"' + list.join(',') + '"';
            for (let r = 1; r <= lastRow; r++) {
                ws.getCell(`${colLetter}${r}`).dataValidation = {
                    type: 'list',
                    allowBlank: true,
                    formulae: [formula],
                    showErrorMessage: true,
                    errorTitle: 'Недопустимое значение',
                    error: 'Выберите значение из списка'
                };
            }
        });
    });

    const buf = await wb.xlsx.writeBuffer();
    const blob = new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    const saveAs = await ensureSaveAs();
    saveAs(blob, filename || 'export.xlsx');
}
