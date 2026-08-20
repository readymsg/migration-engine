// THROWAWAY (BUILD.md step 7). PREVIEW-ONLY site footer, rendered
// BELOW .preview-page. See SiteHeader.tsx docblock — same posture:
// NOT part of createDraftSite's payload, NOT emitted into page
// content, does NOT imply the engine produces site chrome (it does
// not — footers live in the product's siteSettings.zones).

interface Props {
    orgId: string;
}

export default function SiteFooter({ orgId }: Props) {
    const year = new Date().getFullYear();
    return (
        <footer className="preview-site-footer" role="contentinfo">
            <div className="preview-site-footer__row">
                <span className="preview-site-footer__org">{orgId}</span>
                <span className="preview-site-footer__copy">© {year} — rebuilt via TeamLinkt migration engine</span>
            </div>
            <div className="preview-site-footer__note">
                Preview-only chrome. Real site header / footer come from the product's siteSettings.zones once the draft lands.
            </div>
        </footer>
    );
}
