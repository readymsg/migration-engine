// Palette application + WCAG contrast helpers. Plain JS so the same
// module the browser bundle ships is loadable from Node standalone
// tests (no transpile step). App.tsx imports it — TypeScript accepts
// JS modules without types.
//
// TEXT-CONTRAST DISCIPLINE: for every brand color that will host text
// (primary, secondary, accent) we compute the WCAG contrast ratio
// against BOTH white and the brand text color, pick whichever meets
// the 4.5:1 normal-text threshold, and expose it as `--pv-brand-*-on`.
// If NEITHER color meets threshold the brand var stays UNSET so the
// surface falls back to the neutral default — better an unbranded,
// readable surface than an unreadable one.

export const WCAG_MIN_NORMAL = 4.5;

export function applyBrandPaletteTo(sink, palette) {
    if (!palette) return;
    const textColor = palette.text ?? '#1a1a1a';
    applyBrandColor(sink, 'primary', palette.primary, textColor);
    applyBrandColor(sink, 'secondary', palette.secondary, textColor);
    applyBrandColor(sink, 'accent', palette.accent, textColor);
    if (palette.background) sink.setProperty('--pv-brand-background', palette.background);
    if (palette.text) sink.setProperty('--pv-brand-text', palette.text);

    // Soft-tint variant for large surfaces (nav backgrounds, card
    // stripes). `color-mix` is universal in evergreen browsers.
    if (palette.primary) {
        sink.setProperty(
            '--pv-brand-primary-soft',
            `color-mix(in srgb, ${palette.primary} 8%, white)`,
        );
    }
}

export function applyBrandColor(sink, name, color, brandTextColor) {
    if (!color) return;
    const onWhite = contrastRatio(color, '#ffffff');
    const onText = contrastRatio(color, brandTextColor);

    let onColor = null;
    if (onWhite >= WCAG_MIN_NORMAL && onWhite >= onText) {
        onColor = '#ffffff';
    } else if (onText >= WCAG_MIN_NORMAL) {
        onColor = brandTextColor;
    }
    if (onColor === null) return; // fallback path — neutral default takes over.
    sink.setProperty(`--pv-brand-${name}`, color);
    sink.setProperty(`--pv-brand-${name}-on`, onColor);
}

export function contrastRatio(a, b) {
    const la = relativeLuminance(a);
    const lb = relativeLuminance(b);
    const [lighter, darker] = la >= lb ? [la, lb] : [lb, la];
    return (lighter + 0.05) / (darker + 0.05);
}

export function relativeLuminance(hex) {
    const rgb = hexToRgb(hex);
    if (!rgb) return 0;
    const toLin = (c) => {
        const s = c / 255;
        return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * toLin(rgb.r) + 0.7152 * toLin(rgb.g) + 0.0722 * toLin(rgb.b);
}

export function hexToRgb(hex) {
    const cleaned = hex.replace('#', '').trim();
    const expanded = cleaned.length === 3
        ? cleaned.split('').map((c) => c + c).join('')
        : cleaned;
    if (!/^[0-9a-fA-F]{6}$/.test(expanded)) return null;
    return {
        r: parseInt(expanded.slice(0, 2), 16),
        g: parseInt(expanded.slice(2, 4), 16),
        b: parseInt(expanded.slice(4, 6), 16),
    };
}
