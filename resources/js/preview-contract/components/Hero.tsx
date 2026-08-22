import type { Asset, Block } from '../types';
import { resolveContractAsset } from '../asset-resolver';

// Contract Hero block. Prop rename from our old schema:
//   OLD Hero.background_image → CONTRACT Hero.imageUrl
export default function Hero({ block, assets }: { block: Block; assets: Asset[] }) {
    const props = block.props as {
        layout?: 'overlay' | 'split' | 'text' | 'image';
        imageUrl?: string;
        heading?: string;
        subheading?: string;
        preheading?: string;
        primaryButton?: { label?: string; href?: string };
        secondaryButton?: { label?: string; href?: string };
    };
    const resolvedBg = resolveContractAsset(props.imageUrl, assets);

    // Fallback background uses the measured brand primary via CSS
    // custom prop (--cp-primary is set on :root by App based on
    // site.primaryColor). Same posture as our old Hero fallback.
    const style: React.CSSProperties = resolvedBg
        ? {
              backgroundImage: `url("${resolvedBg}")`,
              backgroundSize: 'cover',
              backgroundPosition: 'center',
          }
        : { background: 'var(--cp-primary, #1a1a1a)' };

    return (
        <section className="cp-hero" style={style}>
            <div className="cp-hero__overlay">
                {props.preheading ? <div className="cp-hero__preheading">{props.preheading}</div> : null}
                {props.heading ? <h1 className="cp-hero__heading">{props.heading}</h1> : null}
                {props.subheading ? <p className="cp-hero__subheading">{props.subheading}</p> : null}
                <div className="cp-hero__ctas">
                    {props.primaryButton?.label ? (
                        <a className="cp-hero__cta cp-hero__cta--primary" href={props.primaryButton.href || '#'}>
                            {props.primaryButton.label}
                        </a>
                    ) : null}
                    {props.secondaryButton?.label ? (
                        <a className="cp-hero__cta cp-hero__cta--secondary" href={props.secondaryButton.href || '#'}>
                            {props.secondaryButton.label}
                        </a>
                    ) : null}
                </div>
            </div>
        </section>
    );
}
