/**
 * The settings page's stylesheet must not be able to touch any other part of
 * the site owner's wp-admin: every selector starts with .haman-admin (the
 * class on this page's own .wrap). A bare `.button-primary`, `.form-table`
 * or `#wpcontent` rule added later would restyle every admin screen the
 * moment WordPress ever loads this file elsewhere — this fails first.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const CSS = fs.readFileSync(path.join(__dirname, '..', 'admin', 'css', 'haman-admin.css'), 'utf8');

/** Every selector in the file, including those inside @media blocks. */
function selectors(css) {
    const out = [];
    const src = css.replace(/\/\*[\s\S]*?\*\//g, '');
    let depth = 0, buf = '';
    for (const ch of src) {
        if (ch === '{') {
            const prelude = buf.trim();
            buf = '';
            if (prelude.startsWith('@')) {
                // @media / @supports wrap rules; @keyframes / @font-face would be global.
                assert.match(prelude, /^@(media|supports)\b/, `global at-rule not allowed: ${prelude}`);
            } else {
                prelude.split(',').map((s) => s.trim()).filter(Boolean).forEach((s) => out.push(s));
            }
            depth++;
        } else if (ch === '}') {
            depth--;
            buf = '';
        } else if (ch === ';' ) {
            buf = '';
        } else {
            buf += ch;
        }
    }
    assert.equal(depth, 0, 'unbalanced braces');
    return out;
}

test('every selector is scoped under .haman-admin', () => {
    const all = selectors(CSS);
    assert.ok(all.length > 50, `expected the full stylesheet, parsed ${all.length} selectors`);
    const escaped = all.filter((s) => !/^\.haman-admin(?![\w-])/.test(s));
    assert.deepEqual(escaped, [], 'selectors outside .haman-admin');
});

test('the scope class itself never sits under a broader ancestor', () => {
    // ".wp-admin .haman-admin" would still be scoped, but "body .haman-admin ~ *" etc.
    // could leak — the root must be the first token of every selector.
    const all = selectors(CSS);
    all.forEach((s) => assert.doesNotMatch(s, /[~+]/, `sibling combinator can reach outside the page: ${s}`));
});

test('the stylesheet does not use !important to beat WordPress core', () => {
    assert.doesNotMatch(CSS.replace(/\/\*[\s\S]*?\*\//g, ''), /!important/);
});
