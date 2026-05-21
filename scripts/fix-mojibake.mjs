#!/usr/bin/env node
// One-shot fixer for UTF-8-via-CP1252 mojibake across resource files.
// Replacements are ordered longest-first so we don't strip multi-byte
// garbles before their longer variants get a chance to match.
//
//   Original  →  UTF-8 bytes  →  misread as CP1252  →  mojibake we see
//   ────────────────────────────────────────────────────────────────
//   —  U+2014    E2 80 94      â € "                  â€"
//   –  U+2013    E2 80 93      â € "                  â€" (same surface form)
//   '  U+2019    E2 80 99      â € ™                  â€™
//   '  U+2018    E2 80 98      â € ˜                  â€˜
//   "  U+201D    E2 80 9D      â € (control)         â€  (orphan trailing)
//   "  U+201C    E2 80 9C      â € œ                  â€œ
//   …  U+2026    E2 80 A6      â € ¦                  â€¦
//   ─  U+2500    E2 94 80      â ” €                  â”€
//   ·  U+00B7    C2 B7         Â ·                    Â·
//   °  U+00B0    C2 B0         Â °                    Â°
//   €  U+20AC    E2 82 AC      â ‚ ¬                  â‚¬

import { readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execSync } from 'node:child_process';

const REPLACEMENTS = [
    // Longer/triple-byte patterns first
    ['â”€', '─'],   // box-drawing horizontal
    ['â”‚', '│'],   // box-drawing vertical
    ['â”', '┐'],    // box-drawing top-right
    ['â”˜', '┘'],   // box-drawing bottom-right
    ['â””', '└'],   // box-drawing bottom-left
    ['â”Œ', '┌'],   // box-drawing top-left
    ['â€¦', '…'],   // ellipsis
    ['â€™', "'"],   // right single quote
    ['â€˜', "'"],   // left single quote
    ['â€œ', '"'],   // left double quote
    ['â€¢', '•'],   // bullet (must come BEFORE the orphan â€ rule — otherwise the
                    //         orphan rule eats the â€ leaving a stray ¢ behind)
    ['â€"', '—'],   // em-dash (most common — used in headings & section dividers)
    ['Â€"', '—'],   // double-corrupted em-dash
    ['"”', '—'],    // doubly-mojibaked em-dash, fully decoded into two distinct chars
    ['"“', '—'],    // doubly-mojibaked en/em-dash with left curly quote tail
    ['"¢', '•'],    // bullet fragment created by an earlier over-eager pass — restore
    ['â€', '"'],    // orphan right double quote (must come AFTER all known â€? variants)
    ['â‚¬', '€'],   // euro
    ['Ã©', 'é'],    // e-acute
    ['Ã¨', 'è'],    // e-grave
    ['Ã ', 'à'],    // a-grave
    ['Ã´', 'ô'],    // o-circumflex
    ['Ã—', '×'],    // multiplication sign
    ['Ã·', '÷'],    // division sign
    ['Â©', '©'],    // copyright
    ['Â®', '®'],    // registered
    ['Â¶', '¶'],    // pilcrow
    ['Â½', '½'],    // one-half
    ['Â±', '±'],    // plus-minus
    ['Â·', '·'],    // middle dot (single-byte mojibake — leave near end)
    ['Â°', '°'],    // degree sign
    ['Â ', ' '],    // non-breaking space corruption
];

const targetDirs = ['resources', 'app', 'docs', 'config'];

// Use git ls-files to enumerate tracked files; falls back to nothing if git fails.
function listFiles() {
    try {
        const out = execSync('git ls-files ' + targetDirs.join(' '), { encoding: 'utf8' });
        return out.split('\n').filter(Boolean);
    } catch (e) {
        console.error('git ls-files failed; aborting.');
        process.exit(1);
    }
}

let totalFiles = 0;
let totalReplacements = 0;
const perFile = [];

for (const rel of listFiles()) {
    // Skip binaries, lock files, build artifacts.
    if (/\.(png|jpe?g|gif|webp|ico|pdf|zip|woff2?|ttf|eot|min\.js|min\.css)$/i.test(rel)) continue;
    if (rel.includes('public/build/') || rel.includes('node_modules/') || rel.includes('vendor/')) continue;

    let content;
    try {
        content = await readFile(rel, 'utf8');
    } catch (e) {
        continue;
    }

    let changed = content;
    let fileReps = 0;
    for (const [from, to] of REPLACEMENTS) {
        if (!changed.includes(from)) continue;
        const before = changed.length;
        changed = changed.split(from).join(to);
        const after = changed.length;
        fileReps += (before - after) / (from.length - to.length || 1);
    }

    if (changed !== content) {
        await writeFile(rel, changed, 'utf8');
        totalFiles += 1;
        totalReplacements += fileReps;
        perFile.push([rel, fileReps]);
    }
}

console.log(`\nFixed mojibake in ${totalFiles} files (${totalReplacements} replacements).\n`);
perFile.sort((a, b) => b[1] - a[1]);
for (const [f, n] of perFile.slice(0, 20)) {
    console.log(`  ${n.toString().padStart(4)}  ${f}`);
}
if (perFile.length > 20) console.log(`  ... and ${perFile.length - 20} more`);
