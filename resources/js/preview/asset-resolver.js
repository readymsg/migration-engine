// THROWAWAY (BUILD.md step 7). Client-side asset URL resolver — turns
// s3://-shaped keys emitted by AssetUrlRewriter into
// /preview-assets?p=…&f=… URLs the browser can fetch via
// PreviewAssetController.
//
// Non-s3:// URLs pass through unchanged. If we've already loaded the
// ConversionResult, we invert asset_refs (s3_key → source_url) so the
// controller can fall back to fetching the CDN URL when the local
// bytes aren't on disk.
//
// Design: module-level singleton, seeded once on ConversionResult
// load (see App.tsx). React components import the pure
// `resolvePreviewAsset(url)` function; the module holds the current
// asset_refs internally so components don't need to prop-drill it.

let sourceUrlByS3Key = new Map();

/**
 * Seed the resolver with the loaded ConversionResult's asset_refs.
 * Called once from App.tsx after fetch. Safe to call multiple times
 * (each call replaces the map — reflects the currently-active
 * conversion).
 *
 * @param {Array<{s3_key: string, source_url: string | null}>} assetRefs
 */
export function seedAssetRefs(assetRefs) {
    sourceUrlByS3Key = new Map();
    if (!Array.isArray(assetRefs)) return;
    for (const ref of assetRefs) {
        if (typeof ref?.s3_key !== 'string' || ref.s3_key === '') continue;
        if (typeof ref?.source_url !== 'string' || ref.source_url === '') continue;
        sourceUrlByS3Key.set(ref.s3_key, ref.source_url);
    }
}

/**
 * Turn an s3://-shaped URL into a /preview-assets URL the browser can
 * fetch. Non-s3:// URLs are passed through unchanged so a preview
 * that hasn't been through AssetUrlRewriter (or a URL that wasn't
 * matched by the rewriter) still renders.
 *
 * @param {string | null | undefined} url
 * @returns {string | undefined}
 */
export function resolvePreviewAsset(url) {
    if (typeof url !== 'string' || url === '') return undefined;
    if (!url.startsWith('s3://')) return url;
    const source = sourceUrlByS3Key.get(url);
    const params = new URLSearchParams({ p: url });
    if (source) params.set('f', source);
    return '/preview-assets?' + params.toString();
}

// Exported for the frontend test to inspect state without loading
// React. Not used in the browser bundle.
export function _resolverStateForTest() {
    return { size: sourceUrlByS3Key.size, has: (k) => sourceUrlByS3Key.has(k) };
}
