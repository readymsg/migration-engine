import { resolvePreviewAsset } from '../asset-resolver.js';

interface ImageProps {
    src?: string;
    alt?: string;
    caption?: string;
}

export default function Image({ src, alt = '', caption }: ImageProps) {
    if (!src) {
        return <div className="preview-block preview-image preview-image--missing">image: (no src)</div>;
    }
    const resolved = resolvePreviewAsset(src);
    return (
        <figure className="preview-block preview-image">
            <img src={resolved} alt={alt} />
            {caption ? <figcaption>{caption}</figcaption> : null}
        </figure>
    );
}
