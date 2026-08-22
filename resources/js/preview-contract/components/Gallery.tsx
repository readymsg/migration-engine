import type { Asset, Block } from '../types';
import { resolveContractAsset } from '../asset-resolver';

// Contract Gallery block. Prop rename from our old schema:
//   OLD Gallery.items[]  →  CONTRACT Gallery.images[]
//   OLD Gallery.title     →  CONTRACT Gallery.heading
export default function Gallery({ block, assets }: { block: Block; assets: Asset[] }) {
    const props = block.props as {
        images?: Array<{ src?: string; alt?: string; caption?: string }>;
        columns?: number;
        heading?: string;
    };
    const images = (props.images ?? []).map((img) => ({
        src: resolveContractAsset(img.src, assets),
        alt: img.alt ?? '',
        caption: img.caption ?? '',
    })).filter((i) => i.src !== undefined);

    if (images.length === 0) return null;
    const columns = props.columns ?? 3;
    return (
        <section className="cp-gallery">
            {props.heading ? <h2 className="cp-gallery__heading">{props.heading}</h2> : null}
            <div
                className="cp-gallery__grid"
                style={{ gridTemplateColumns: `repeat(${columns}, 1fr)` }}
            >
                {images.map((img, i) => (
                    <figure key={i} className="cp-gallery__item">
                        <img src={img.src} alt={img.alt} loading="lazy" />
                        {img.caption ? <figcaption>{img.caption}</figcaption> : null}
                    </figure>
                ))}
            </div>
        </section>
    );
}
