import { CSSProperties } from 'react';

interface HeroProps {
    heading?: string;
    subheading?: string;
    background_image?: string;
    cta?: { label?: string; href?: string } | null;
}

export default function Hero({ heading, subheading, background_image, cta }: HeroProps) {
    const style: CSSProperties = background_image
        ? {
              backgroundImage: `url("${background_image}")`,
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
