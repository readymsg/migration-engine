interface CardProps {
    title?: string;
    body?: string;
    image?: string;
    href?: string;
}

export default function Card({ title, body, image, href }: CardProps) {
    const inner = (
        <>
            {image ? <img className="preview-card__image" src={image} alt="" /> : null}
            <div className="preview-card__body">
                {title ? <h3 className="preview-card__title">{title}</h3> : null}
                {body ? <p className="preview-card__text">{body}</p> : null}
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
