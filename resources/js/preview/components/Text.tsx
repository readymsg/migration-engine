interface TextProps {
    body?: string;
    align?: 'left' | 'center' | 'right';
}

export default function Text({ body, align = 'left' }: TextProps) {
    return (
        <div
            className="preview-block preview-text"
            style={{ textAlign: align }}
        >
            {(body ?? '').split('\n\n').map((para, i) => (
                <p key={i}>{para}</p>
            ))}
        </div>
    );
}
