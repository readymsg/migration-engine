// Pure-computation tests for the palette-application helpers in
// resources/js/preview/brand-palette.js. Same module the browser
// bundle ships — no transpile step.
//
// The contract these tests pin:
//   1. WCAG 4.5:1 threshold decides `--pv-brand-<name>-on` color.
//   2. When NEITHER white nor the brand text color meets threshold
//      on a palette color, that brand var is NOT set — the CSS
//      neutral fallback takes over.
//   3. tbirdhoops palette applies without contrast failures.
//   4. Bright-yellow accent picks DARK text (not white) because
//      white-on-yellow would be unreadable.
//   5. A pathological grey palette refuses to overwrite the neutral
//      default rather than shipping unreadable output.
//
// Runs standalone: `node tests/frontend/brand-palette.test.mjs`.

import {
    applyBrandPaletteTo,
    applyBrandColor,
    contrastRatio,
    hexToRgb,
    WCAG_MIN_NORMAL,
} from '../../resources/js/preview/brand-palette.js';

let failed = 0;
function assert(cond, msg) {
    if (!cond) { console.error('  ✗', msg); failed++; }
    else { console.log('  ✓', msg); }
}
function almostEqual(a, b, tol = 0.05) { return Math.abs(a - b) <= tol; }

console.log('\n--- WCAG threshold constant ---');
assert(WCAG_MIN_NORMAL === 4.5, 'WCAG normal-text threshold is 4.5:1');

console.log('\n--- contrastRatio pins ---');
assert(almostEqual(contrastRatio('#ffffff', '#000000'), 21), 'white on black is 21:1');
assert(almostEqual(contrastRatio('#ffffff', '#ffffff'), 1), 'white on white is 1:1');

const primaryOnWhite = contrastRatio('#1F3A93', '#ffffff');
assert(primaryOnWhite > 4.5, `tbirdhoops primary #1F3A93 on white passes 4.5:1 (was ${primaryOnWhite.toFixed(2)})`);

const accentOnWhite = contrastRatio('#F1C40F', '#ffffff');
assert(accentOnWhite < 4.5, `bright yellow #F1C40F on white FAILS 4.5:1 (was ${accentOnWhite.toFixed(2)})`);

const accentOnDark = contrastRatio('#F1C40F', '#1A1A1A');
assert(accentOnDark > 4.5, `bright yellow on dark text PASSES 4.5:1 (was ${accentOnDark.toFixed(2)})`);

console.log('\n--- applyBrandColor: sets var + -on when contrast passes ---');
{
    const sink = makeSink();
    applyBrandColor(sink, 'primary', '#1F3A93', '#1a1a1a');
    assert(sink.get('--pv-brand-primary') === '#1F3A93', 'primary var set');
    assert(sink.get('--pv-brand-primary-on') === '#ffffff', 'primary-on = white (best contrast)');
}

console.log('\n--- applyBrandColor: picks brand text when white fails but text passes ---');
{
    const sink = makeSink();
    applyBrandColor(sink, 'accent', '#F1C40F', '#1a1a1a');
    assert(sink.get('--pv-brand-accent') === '#F1C40F', 'accent var set');
    assert(sink.get('--pv-brand-accent-on') === '#1a1a1a', 'accent-on = brand text (white unreadable)');
}

console.log('\n--- applyBrandColor: FALLS BACK when neither text option meets threshold ---');
{
    const sink = makeSink();
    // Mid grey against mid greys — both fail.
    applyBrandColor(sink, 'primary', '#8a8a8a', '#7a7a7a');
    assert(sink.get('--pv-brand-primary') === undefined, 'primary NOT set (neutral fallback wins)');
    assert(sink.get('--pv-brand-primary-on') === undefined, 'primary-on NOT set');
}

console.log('\n--- applyBrandPaletteTo: tbirdhoops full palette ---');
{
    const sink = makeSink();
    applyBrandPaletteTo(sink, {
        primary: '#1F3A93',
        secondary: '#C0392B',
        accent: '#F1C40F',
        background: '#FFFFFF',
        text: '#1A1A1A',
    });
    assert(sink.get('--pv-brand-primary') === '#1F3A93', 'primary set');
    assert(sink.get('--pv-brand-primary-on') === '#ffffff', 'primary-on = white');
    assert(sink.get('--pv-brand-secondary') === '#C0392B', 'secondary set');
    assert(sink.get('--pv-brand-secondary-on') === '#ffffff', 'secondary-on = white');
    assert(sink.get('--pv-brand-accent') === '#F1C40F', 'accent set (dark text passes)');
    assert(sink.get('--pv-brand-accent-on') === '#1A1A1A', 'accent-on = brand text (white unreadable)');
    assert(sink.get('--pv-brand-background') === '#FFFFFF', 'background set');
    assert(sink.get('--pv-brand-text') === '#1A1A1A', 'text set');
    assert(sink.get('--pv-brand-primary-soft') !== undefined, 'primary-soft set');
    assert(sink.get('--pv-brand-primary-soft').includes('color-mix'), 'primary-soft uses color-mix');
}

console.log('\n--- applyBrandPaletteTo: refuses to overwrite when a color would break contrast ---');
{
    const sink = makeSink();
    applyBrandPaletteTo(sink, {
        primary: '#8a8a8a',
        text: '#7a7a7a',
    });
    assert(
        sink.get('--pv-brand-primary') === undefined,
        'primary NOT set — grey-on-grey would ship unreadable text',
    );
}

console.log('\n--- hexToRgb + short-hex expansion ---');
{
    assert(hexToRgb('#ffffff').r === 255, 'ffffff → 255,255,255');
    assert(hexToRgb('#fff').r === 255, 'short-hex #fff expands to #ffffff');
    assert(hexToRgb('not-a-hex') === null, 'garbage input returns null');
}

if (failed > 0) {
    console.error(`\nbrand-palette: ${failed} assertion(s) failed`);
    process.exit(1);
}
console.log('\nbrand-palette: all assertions passed');

function makeSink() {
    const store = new Map();
    return {
        setProperty: (name, value) => store.set(name, value),
        get: (name) => store.get(name),
    };
}
