import Markdown from 'markdown-to-jsx';

interface TextProps {
    body?: string;
    align?: 'left' | 'center' | 'right';
}

// Renders prose bodies (paragraphs, headings, emphasis, links, bulleted
// lists) — the shapes that legitimately appear in News, About, and
// long-form club copy scraped from real sites. The block-fill agent
// puts markdown into `body` per its prompt (e.g., "### Section", list
// items with `[label](url)`), and Firecrawl-captured emphasis (`**bold**`,
// `*italic*`) is preserved verbatim from the source. Without parsing,
// visitors see literal `###` and `**` characters in the preview.
//
// markdown-to-jsx (~9KB minzipped) chosen over react-markdown (~35KB)
// for size given the standard markdown surface we need. React-native
// output, no dangerouslySetInnerHTML — XSS-safe for Firecrawl-scraped
// content that might carry unexpected HTML.
export default function Text({ body, align = 'left' }: TextProps) {
    return (
        <div
            className="preview-block preview-text preview-prose"
            style={{ textAlign: align }}
        >
            <Markdown>{body ?? ''}</Markdown>
        </div>
    );
}
