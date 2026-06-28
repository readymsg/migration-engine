interface HeadingProps {
    text?: string;
    level?: 'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6';
}

export default function Heading({ text, level = 'h2' }: HeadingProps) {
    const Tag = level;
    return (
        <Tag className={`preview-block preview-heading preview-heading--${level}`}>
            {text ?? ''}
        </Tag>
    );
}
