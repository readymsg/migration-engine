// XSS-safety proof for the preview's markdown-to-jsx integration.
//
// Text.tsx and Card.tsx render Firecrawl-scraped `body` content through
// markdown-to-jsx. Firecrawl output is NOT trusted — a source site's
// HTML could carry inline <script>, <iframe>, `javascript:` links,
// event handlers, or other attack shapes. This test runs each hostile
// shape through the ACTUAL library (via ReactDOMServer to serialize
// what a browser would receive) and asserts the output is inert.
//
// Runs standalone: `node tests/frontend/markdown-xss-safety.test.mjs`.
// No test framework — just Node + the same deps the preview bundle
// uses (markdown-to-jsx + react-dom). Exits non-zero on any failure.
//
// If a case regresses in a future markdown-to-jsx upgrade, this test
// catches it before deploy. See DEPLOY.md for the pre-share smoke.

import { compiler } from 'markdown-to-jsx';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { renderToStaticMarkup } = require('react-dom/server');

let failures = 0;
const results = [];

function probe(name, input, assertion, expectation) {
    let out;
    try {
        out = renderToStaticMarkup(compiler(input));
    } catch (e) {
        out = '[threw] ' + e.message;
    }
    const ok = assertion(out);
    if (!ok) failures++;
    results.push({ ok, name, input, out, expectation });
}

// ─── Link URL sanitization ───────────────────────────────────────────
probe(
    'javascript: href stripped',
    '[click me](javascript:alert("xss"))',
    (out) => out === '<a>click me</a>',
    'anchor kept but href removed entirely (no javascript: leaks)',
);

probe(
    'data: href stripped (script-carrying)',
    '[click](data:text/html,<script>alert(1)</script>)',
    (out) => !out.includes('href="data:'),
    'no href="data:" survives',
);

probe(
    'vbscript: href stripped',
    '[click](vbscript:msgbox(1))',
    (out) => !out.includes('href="vbscript:'),
    'no href="vbscript:" survives',
);

probe(
    'url-encoded javascript: href stripped',
    '[click](java%73cript:alert(1))',
    (out) => !out.toLowerCase().includes('javascript:') && !out.toLowerCase().includes('java%73cript:'),
    'sanitizer decodes before checking — encoded variant caught',
);

// ─── Legit URLs must pass through ──────────────────────────────────
probe(
    'http/https link renders',
    '[home](https://example.com/foo)',
    (out) => out.includes('href="https://example.com/foo"'),
    'legit URLs preserved',
);

probe(
    'mailto link renders',
    '[email](mailto:hi@example.com)',
    (out) => out.includes('href="mailto:hi@example.com"'),
    'mailto preserved',
);

// ─── Raw HTML tag filtering (markdown-to-jsx v9 tagfilter default) ──
probe(
    '<script> escaped as text',
    'hello <script>alert("xss")</script> world',
    (out) => !out.toLowerCase().includes('<script') && out.includes('&lt;script&gt;'),
    'script tag rendered as HTML-escaped text, not executable',
);

probe(
    '<iframe> escaped as text',
    '<iframe src="https://evil.example"></iframe>',
    (out) => !out.toLowerCase().includes('<iframe') && out.includes('&lt;iframe'),
    'iframe rendered as escaped text',
);

probe(
    '<style> escaped as text',
    '<style>body{background:red}</style>',
    (out) => !out.toLowerCase().includes('<style') && out.includes('&lt;style&gt;'),
    'style block not injected into the page',
);

// ─── Event-handler attributes (React strips these) ─────────────────
probe(
    'img onerror stripped',
    '<img src="x" onerror="alert(\'xss\')" alt="x">',
    (out) => !out.toLowerCase().includes('onerror'),
    'React silently drops lowercase event props — onerror gone',
);

probe(
    '<a onclick> stripped',
    '<a href="https://example.com" onclick="alert(1)">click</a>',
    (out) => !out.toLowerCase().includes('onclick'),
    'React drops onclick — href preserved, handler gone',
);

probe(
    'onclick in link title becomes a title attribute (not executable)',
    '[click](https://example.com "onclick=alert(1)")',
    (out) => out.includes('title="onclick=alert(1)"') && !/on\w+="/i.test(out.replace('title="onclick=alert(1)"', '')),
    'string ends up as title tooltip — not a live event handler',
);

// ─── Legit markdown must still render ──────────────────────────────
probe(
    'bold + italic renders as <strong>/<em>',
    '**bold** and *italic* text',
    (out) => out.includes('<strong>bold</strong>') && out.includes('<em>italic</em>'),
    'legit emphasis works — this is the whole reason we added the lib',
);

probe(
    'heading renders as <h3>',
    '### Section Title',
    (out) => /<h3[^>]*>Section Title<\/h3>/.test(out),
    'legit headings render properly',
);

probe(
    'bullet list renders as <ul><li>',
    '- one\n- two\n- three',
    (out) => out.includes('<ul>') && out.includes('<li>one</li>'),
    'bulleted lists render as real HTML',
);

probe(
    'markdown link inside prose renders anchor',
    'see the [docs](https://example.com/docs) for more',
    (out) => out.includes('<a href="https://example.com/docs">docs</a>'),
    'inline links render',
);

// ─── Report ────────────────────────────────────────────────────────
console.log(`Ran ${results.length} XSS-safety probes against markdown-to-jsx defaults.\n`);

for (const r of results) {
    const marker = r.ok ? '✓' : '✗';
    console.log(`${marker} ${r.name}`);
    if (!r.ok) {
        console.log(`   input:  ${JSON.stringify(r.input)}`);
        console.log(`   output: ${r.out}`);
        console.log(`   expected: ${r.expectation}`);
    }
}

console.log();
if (failures === 0) {
    console.log(`✓ All ${results.length} XSS-safety probes passed. Preview markdown rendering is safe under current markdown-to-jsx defaults.`);
    process.exit(0);
} else {
    console.log(`✗ ${failures} of ${results.length} probes FAILED. XSS exposure — do NOT deploy.`);
    process.exit(1);
}
