import { CSSProperties } from 'react';
import { resolvePreviewAsset } from '../asset-resolver.js';

interface HeroProps {
    heading?: string;
    subheading?: string;
    background_image?: string;
    cta?: { label?: string; href?: string } | null;
}

export default function Hero({ heading, subheading, background_image, cta }: HeroProps) {
    const resolvedBg = resolvePreviewAsset(background_image);
    const style: CSSProperties = resolvedBg
        ? {
              backgroundImage: `url("${resolvedBg}")`,
              backgroundSize: 'cover',
              backgroundPosition: 'center',
          }
        : { background: '#1a1a1a' };

    return (
        <section className="preview-block preview-hero" style={style}>
            <div className="preview-hero__overlay">
                {heading ? <h1 className="preview-hero__heading">{heading}</h1> : null}
                {subheading ? <p className="preview-hero__subheading">{subheading}</p> : null}
                {cta?.label ? (
                    <a className="preview-hero__cta" href={cta.href ?? '#'}>
                        {cta.label}
                    </a>
                ) : null}
            </div>
        </section>
    );
}
