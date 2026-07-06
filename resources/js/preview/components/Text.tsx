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
// output, no dangerouslySetInnerHTML.
//
// XSS posture (verified empirically — 16 hostile-input probes in
// tests/frontend/markdown-xss-safety.test.mjs, run via
// `npm run test:frontend-xss`):
//   - javascript:/data:/vbscript: hrefs → sanitizer strips the href
//     (URL-encoded variants like java%73cript: also caught)
//   - <script>/<iframe>/<style>/etc → v9 tagfilter escapes them as
//     text; no executable tag survives
//   - inline event handlers (onerror, onclick, ...) → React itself
//     drops lowercase event props at render time; anchor/img keep
//     other attrs but the handler is gone
//   - legit http/https/mailto hrefs, emphasis, headings, lists all
//     pass through as real HTML
// Run the probe suite before any markdown-to-jsx upgrade — a regression
// there would mean deploying an XSS vector into the preview.
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
