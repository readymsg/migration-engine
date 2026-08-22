import type { Block } from '../types';

// Contract Text block. Contract Part III Text schema:
//   body: richtext (HTML sanitised to TipTap subset by our
//     RichTextSanitizer in Slice 3)
//   as: 'p' | 'h1' | 'h2' | 'h3'
//   align: 'left' | 'center' | 'right'
//   color, fontSize, maxWidth, horizontal/verticalPadding
//
// dangerouslySetInnerHTML is safe here BECAUSE the sanitiser has
// already stripped every disallowed vocabulary node — no <script>,
// no <iframe>, no event handlers. If the sanitiser drifts, this
// component's safety drifts with it — the guard is the sanitiser,
// not this render.
export default function Text({ block }: { block: Block }) {
    const props = block.props as {
        body?: string;
        as?: 'p' | 'h1' | 'h2' | 'h3';
        align?: 'left' | 'center' | 'right';
        color?: string;
        fontSize?: number;
    };

    const tag = props.as ?? 'p';
    const body = props.body ?? '';
    const style: React.CSSProperties = {
        textAlign: props.align ?? 'left',
        color: props.color && !props.color.startsWith('var(') ? props.color : undefined,
        fontSize: props.fontSize ? `${props.fontSize}px` : undefined,
    };

    const Tag = tag as keyof React.JSX.IntrinsicElements;
    return (
        <Tag
            className={`cp-text cp-text--${tag}`}
            style={style}
            dangerouslySetInnerHTML={{ __html: body }}
        />
    );
}
