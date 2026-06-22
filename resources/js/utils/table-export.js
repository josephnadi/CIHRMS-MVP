const toolbarClass = 'cihrms-table-export-toolbar';
const enhancedAttr = 'data-cihrms-export-enhanced';

function text(value) {
    return String(value ?? '').replace(/\s+/g, ' ').trim();
}

function safeFilename(value) {
    return text(value)
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 80) || 'table-export';
}

function safeSpreadsheetCell(value) {
    const cleaned = text(value);
    return /^[=+\-@\t\r]/.test(cleaned) ? `'${cleaned}` : cleaned;
}

function csvEscape(value) {
    const cleaned = safeSpreadsheetCell(value);
    return /[",\n]/.test(cleaned) ? `"${cleaned.replaceAll('"', '""')}"` : cleaned;
}

function tableTitle(table) {
    const labelledBy = table.getAttribute('aria-labelledby');
    if (labelledBy) {
        const label = document.getElementById(labelledBy);
        if (label) return text(label.textContent);
    }

    const caption = table.querySelector('caption');
    if (caption) return text(caption.textContent);

    const panel = table.closest('section, article, main, .rounded-2xl, .rounded-3xl, .card, [data-export-title]');
    const explicit = panel?.getAttribute('data-export-title');
    if (explicit) return text(explicit);

    const heading = panel?.querySelector('h1, h2, h3, h4, [data-export-heading]');
    if (heading) return text(heading.textContent);

    const pageHeading = document.querySelector('h1, h2');
    return pageHeading ? text(pageHeading.textContent) : 'Table export';
}

function rowsFromTable(table) {
    return Array.from(table.querySelectorAll('tr'))
        .filter((row) => row.offsetParent !== null)
        .map((row) => Array.from(row.querySelectorAll('th, td'))
            .filter((cell) => cell.offsetParent !== null && !cell.closest('[data-export-ignore]'))
            .map((cell) => safeSpreadsheetCell(cell.innerText || cell.textContent)))
        .filter((row) => row.some(Boolean));
}

function downloadBlob(contents, filename, type) {
    const blob = new Blob([contents], { type });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

function exportCsv(table) {
    const title = tableTitle(table);
    const rows = rowsFromTable(table);
    const csv = rows.map((row) => row.map(csvEscape).join(',')).join('\n');
    downloadBlob(`\uFEFF${csv}`, `${safeFilename(title)}.csv`, 'text/csv;charset=utf-8');
}

function escapeHtml(value) {
    return text(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function tableHtml(table) {
    const rows = rowsFromTable(table);
    return `<table><tbody>${rows.map((row) => `<tr>${row.map((cell) => `<td>${escapeHtml(cell)}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
}

function exportExcel(table) {
    const title = tableTitle(table);
    const html = `<!doctype html><html><head><meta charset="utf-8"></head><body>${tableHtml(table)}</body></html>`;
    downloadBlob(html, `${safeFilename(title)}.xls`, 'application/vnd.ms-excel;charset=utf-8');
}

function printTable(table, mode = 'print') {
    const title = tableTitle(table);
    const popup = window.open('', '_blank', 'width=1100,height=800');
    if (!popup) return;

    popup.document.write(`<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>${escapeHtml(title)}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #111827; }
        h1 { font-size: 20px; margin: 0 0 16px; }
        table { border-collapse: collapse; width: 100%; font-size: 12px; }
        td, th { border: 1px solid #d1d5db; padding: 8px; text-align: left; vertical-align: top; }
        tr:first-child td { background: #f3f4f6; font-weight: 700; text-transform: uppercase; }
        .hint { color: #64748b; font-size: 11px; margin-bottom: 16px; }
        @media print { .hint { display: none; } }
    </style>
</head>
<body>
    <h1>${escapeHtml(title)}</h1>
    ${mode === 'pdf' ? '<p class="hint">Choose “Save as PDF” in the print destination to download a PDF copy.</p>' : ''}
    ${tableHtml(table)}
</body>
</html>`);
    popup.document.close();
    popup.focus();
    setTimeout(() => popup.print(), 250);
}

function button(label, action, title) {
    const element = document.createElement('button');
    element.type = 'button';
    element.className = 'cihrms-table-export-button';
    element.textContent = label;
    element.title = title;
    element.addEventListener('click', action);
    return element;
}

function enhanceTable(table) {
    if (!(table instanceof HTMLTableElement)) return;
    if (table.hasAttribute(enhancedAttr) || table.closest('[data-export-ignore]')) return;
    if (table.querySelectorAll('tr').length < 1) return;

    table.setAttribute(enhancedAttr, 'true');

    const toolbar = document.createElement('div');
    toolbar.className = toolbarClass;
    toolbar.setAttribute('data-export-ignore', 'true');
    toolbar.append(
        button('Print', () => printTable(table), 'Print this table'),
        button('PDF', () => printTable(table, 'pdf'), 'Save this table as PDF from the print dialog'),
        button('CSV', () => exportCsv(table), 'Download this table as CSV'),
        button('Excel', () => exportExcel(table), 'Download this table as Excel-compatible XLS'),
    );

    const scrollWrap = table.parentElement?.classList.contains('overflow-x-auto') ? table.parentElement : null;
    const insertionTarget = scrollWrap || table;
    insertionTarget.parentElement?.insertBefore(toolbar, insertionTarget);
}

export function enhanceExportableTables(root = document) {
    root.querySelectorAll?.('table').forEach(enhanceTable);
}

export function initTableExportEnhancer() {
    const run = () => requestAnimationFrame(() => enhanceExportableTables(document));
    run();

    const observer = new MutationObserver((mutations) => {
        if (mutations.some((mutation) => Array.from(mutation.addedNodes).some((node) => node.nodeType === Node.ELEMENT_NODE))) {
            run();
        }
    });

    observer.observe(document.body, { childList: true, subtree: true });
    window.addEventListener('cihrms:tables-ready', run);
}
