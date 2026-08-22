import type { Asset } from './types';

// Resolves `tl-asset:<ref>` tokens in block/site props to a URL the
// browser can render. The payload SENT to TeamLinkt carries the
// tokens verbatim + declares the ORIGINAL third-party sourceUrl in
// assets[]. TeamLinkt fetches server-side.
//
// For the M1 PREVIEW, we can't do server-side fetching (this is a
// browser bundle), so we route the resolved sourceUrl through our
// existing /preview-assets endpoint — the identical pattern we
// built for s3:// keys. PreviewAssetController accepts a raw URL
// via the `f` query param and streams it back with a visible
// X-Preview-Asset-Source header.
//
// The one difference from the old /preview asset flow: no s3://
// keys in the payload at all — everything's a tl-asset:<ref> or a
// direct http URL, and we resolve them the same way.
export function resolveContractAsset(
    value: string | undefined | null,
    assets: Asset[],
): string | undefined {
    if (value === undefined || value === null || value === '') return undefined;

    // tl-asset:<ref> — look up in the envelope's assets ledger.
    if (value.startsWith('tl-asset:')) {
        const ref = value.slice('tl-asset:'.length);
        const asset = assets.find((a) => a.ref === ref);
        if (asset === undefined) {
            // Envelope validator (Slice 9) rejects unreferenced
            // tokens, so this branch shouldn't fire in practice.
            // If it does, render a placeholder so the reviewer
            // sees the missing-declaration bug rather than a
            // broken-image icon.
            console.warn(`[preview-contract] no assets[] entry for tl-asset:${ref}`);
            return undefined;
        }
        return proxyThroughPreviewAssets(asset.sourceUrl);
    }

    // Absolute URL or root-relative — pass through as-is. The
    // contract accepts both.
    if (value.startsWith('http://') || value.startsWith('https://')) {
        return proxyThroughPreviewAssets(value);
    }
    if (value.startsWith('/')) {
        return value;
    }

    return undefined;
}

function proxyThroughPreviewAssets(sourceUrl: string): string {
    // PreviewAssetController serves via GET /preview-assets?p=<placeholder>&f=<sourceUrl>
    // and follows f as an HTTP fallback (matches the s3://-key pattern from the
    // old preview stack). Reuse it here — same shape, different input token.
    // Use a dummy `p` value; the controller falls back to `f` when the local
    // file doesn't exist, which it won't for these.
    const p = encodeURIComponent('contract://' + sourceUrl);
    const f = encodeURIComponent(sourceUrl);
    return `/preview-assets?p=${p}&f=${f}`;
}
