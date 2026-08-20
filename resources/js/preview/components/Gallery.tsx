import { resolvePreviewAsset } from '../asset-resolver.js';

// Emitted by GalleryFiller (see App\Services\Generate\GalleryFiller).
// Native Gallery block populated from source markdown — { title,
// items: [{ src, alt, caption? }] }. Every image goes through
// resolvePreviewAsset so s3:// keys become /preview-assets URLs.

interface GalleryItem {
    src?: string;
    alt?: string;
    caption?: string;
}

interface GalleryProps {
    title?: string;
    items?: GalleryItem[];
}

export default function Gallery({ title, items = [] }: GalleryProps) {
    const cleaned = items.filter((it): it is GalleryItem => typeof it?.src === 'string' && it.src !== '');
    if (cleaned.length === 0) {
        return (
            <section className="preview-block preview-gallery preview-gallery--empty">
                {title ? <h3 className="preview-gallery__title">{title}</h3> : null}
                <p className="preview-gallery__empty-note">gallery: (no items)</p>
            </section>
        );
    }
    return (
        <section className="preview-block preview-gallery">
            {title ? <h3 className="preview-gallery__title">{title}</h3> : null}
            <div className="preview-gallery__grid">
                {cleaned.map((it, i) => {
                    const resolved = resolvePreviewAsset(it.src);
                    return (
                        <figure key={i} className="preview-gallery__item">
                            <img src={resolved} alt={it.alt ?? ''} />
                            {it.caption ? <figcaption>{it.caption}</figcaption> : null}
                        </figure>
                    );
                })}
            </div>
        </section>
    );
}
