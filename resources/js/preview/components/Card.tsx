import Markdown from 'markdown-to-jsx';

interface CardProps {
    title?: string;
    body?: string;
    image?: string;
    href?: string;
}

// Card body is rendered through markdown-to-jsx — same parity as Text
// (see Text.tsx docblock, including the XSS-safety posture verified
// by tests/frontend/markdown-xss-safety.test.mjs). Card bodies are
// usually short prose ("Read More" tags, one-paragraph blurbs) but
// some sources render bold/italic or short links inside them. Without
// markdown parsing, `**foo**` shows literally in the preview.
//
// The former outer `<p>` around body was replaced with a wrapper `<div>`
// so markdown-to-jsx can emit its own paragraph elements without
// nesting `<p>` inside `<p>` (invalid HTML).
export default function Card({ title, body, image, href }: CardProps) {
    const inner = (
        <>
            {image ? <img className="preview-card__image" src={image} alt="" /> : null}
            <div className="preview-card__body">
                {title ? <h3 className="preview-card__title">{title}</h3> : null}
                {body ? (
                    <div className="preview-card__text preview-prose">
                        <Markdown>{body}</Markdown>
                    </div>
                ) : null}
            </div>
        </>
    );
    if (href) {
        return (
            <a className="preview-block preview-card preview-card--linked" href={href}>
                {inner}
            </a>
        );
    }
    return <div className="preview-block preview-card">{inner}</div>;
}
