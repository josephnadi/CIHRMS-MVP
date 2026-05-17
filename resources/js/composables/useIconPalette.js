/**
 * useIconPalette — single source of truth for card icon colours across the app.
 *
 * Every card icon should pick its colour through this composable (or via the
 * matching .icon-* utility classes in app.css) so the institutional palette
 * and the 5% gold rule stay consistent everywhere.
 */

// Module/category slug → palette colour
const PALETTE = {
    // ── Flagship — gold (5% accent reserve) ──
    'overview':        { name: 'gold',    color: '#ffd700', deep: '#b88a08', cls: 'icon-gold' },
    'reports':         { name: 'gold',    color: '#ffd700', deep: '#b88a08', cls: 'icon-gold' },
    'ag-reports':      { name: 'gold',    color: '#b88a08', deep: '#7a5a05', cls: 'icon-gold' },
    'flagship':        { name: 'gold',    color: '#ffd700', deep: '#b88a08', cls: 'icon-gold' },

    // ── Cyan — technology, time, learning ──
    'attendance':                 { name: 'cyan', color: '#12d9e3', deep: '#0e8a93', cls: 'icon-cyan' },
    'attendance-me':              { name: 'cyan', color: '#12d9e3', deep: '#0e8a93', cls: 'icon-cyan' },
    'attendance-shifts':          { name: 'cyan', color: '#12d9e3', deep: '#0e8a93', cls: 'icon-cyan' },
    'attendance-corrections':     { name: 'cyan', color: '#12d9e3', deep: '#0e8a93', cls: 'icon-cyan' },
    'learning':                   { name: 'cyan', color: '#12d9e3', deep: '#0e8a93', cls: 'icon-cyan' },
    'learning-my':                { name: 'cyan', color: '#12d9e3', deep: '#0e8a93', cls: 'icon-cyan' },
    'learning-skills':            { name: 'cyan', color: '#12d9e3', deep: '#0e8a93', cls: 'icon-cyan' },
    'dept-it':                    { name: 'cyan', color: '#12d9e3', deep: '#0e8a93', cls: 'icon-cyan' },
    'tickets':                    { name: 'cyan', color: '#12d9e3', deep: '#0e8a93', cls: 'icon-cyan' },
    'integrations':               { name: 'cyan', color: '#12d9e3', deep: '#0e8a93', cls: 'icon-cyan' },
    'tech':                       { name: 'cyan', color: '#12d9e3', deep: '#0e8a93', cls: 'icon-cyan' },

    // ── Magenta — people, HR, performance ──
    'employees':                  { name: 'magenta', color: '#d912e3', deep: '#a30aa9', cls: 'icon-magenta' },
    'recruitment':                { name: 'magenta', color: '#d912e3', deep: '#a30aa9', cls: 'icon-magenta' },
    'performance':                { name: 'magenta', color: '#d912e3', deep: '#a30aa9', cls: 'icon-magenta' },
    'performance-goals':          { name: 'magenta', color: '#d912e3', deep: '#a30aa9', cls: 'icon-magenta' },
    'performance-reviews':        { name: 'magenta', color: '#d912e3', deep: '#a30aa9', cls: 'icon-magenta' },
    'performance-contracts':      { name: 'magenta', color: '#d912e3', deep: '#a30aa9', cls: 'icon-magenta' },
    'performance-calibration':    { name: 'magenta', color: '#d912e3', deep: '#a30aa9', cls: 'icon-magenta' },
    'performance-pips':           { name: 'magenta', color: '#d912e3', deep: '#a30aa9', cls: 'icon-magenta' },
    'performance-9box':           { name: 'magenta', color: '#d912e3', deep: '#a30aa9', cls: 'icon-magenta' },
    'dept-hr':                    { name: 'magenta', color: '#d912e3', deep: '#a30aa9', cls: 'icon-magenta' },
    'people':                     { name: 'magenta', color: '#d912e3', deep: '#a30aa9', cls: 'icon-magenta' },

    // ── Blue family — operational, financial, governance ──
    'leave':                      { name: 'sky',  color: '#7986cb', deep: '#1a237e', cls: 'icon-sky' },
    'payroll':                    { name: 'blue', color: '#1a237e', deep: '#0d1452', cls: 'icon-brand' },
    'payments':                   { name: 'blue', color: '#1a237e', deep: '#0d1452', cls: 'icon-brand' },
    'loans':                      { name: 'blue', color: '#1a237e', deep: '#0d1452', cls: 'icon-brand' },
    'offboarding':                { name: 'sky',  color: '#7986cb', deep: '#1a237e', cls: 'icon-sky' },
    'governance':                 { name: 'blue', color: '#1a237e', deep: '#0d1452', cls: 'icon-brand' },
    'assets':                     { name: 'sky',  color: '#7986cb', deep: '#1a237e', cls: 'icon-sky' },
    'dept-finance':               { name: 'blue', color: '#1a237e', deep: '#0d1452', cls: 'icon-brand' },
    'dept-marketing':             { name: 'sky',  color: '#7986cb', deep: '#1a237e', cls: 'icon-sky' },
    'benefits':                   { name: 'blue', color: '#1a237e', deep: '#0d1452', cls: 'icon-brand' },
    'complaints':                 { name: 'sky',  color: '#7986cb', deep: '#1a237e', cls: 'icon-sky' },
    'whistleblower':              { name: 'sky',  color: '#7986cb', deep: '#1a237e', cls: 'icon-sky' },

    // ── Semantic — keep these for status meanings only ──
    'success':                    { name: 'success', color: '#059669', deep: '#047857', cls: 'icon-success' },
    'warning':                    { name: 'warning', color: '#d97706', deep: '#b45309', cls: 'icon-warning' },
    'danger':                     { name: 'danger',  color: '#dc2626', deep: '#b91c1c', cls: 'icon-danger' },
};

const DEFAULT = { name: 'brand', color: '#1a237e', deep: '#0d1452', cls: 'icon-brand' };

export function useIconPalette() {
    const get = (key) => PALETTE[key] ?? DEFAULT;
    const color = (key) => get(key).color;
    const cls   = (key) => get(key).cls;
    const rgb   = (key) => {
        const hex = get(key).color.replace('#', '');
        const r = parseInt(hex.slice(0, 2), 16);
        const g = parseInt(hex.slice(2, 4), 16);
        const b = parseInt(hex.slice(4, 6), 16);
        return `${r},${g},${b}`;
    };
    return { palette: PALETTE, get, color, cls, rgb };
}
