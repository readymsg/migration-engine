<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

// Down-converts scraped HTML to the TipTap-subset the contract's five
// richtext-permitted props accept. Site Import Contract Part II
// "Rich text" — verbatim:
//   "Send a <table> and it *will* render on the published site,
//    because the renderer injects your stored HTML verbatim. But the
//    first time a human opens that field in the editor, TipTap
//    parses the HTML into its own schema and SILENTLY DROPS every
//    node it does not recognise. The content looks fine until
//    someone edits it, then disappears."
//
// This is a silent-loss channel and it is closed here BY
// CONSTRUCTION — the emitter never produces markup outside the
// allowed vocabulary. If a scraped source has a <table> layout, this
// class discards it; the mapper (Slice 5) re-expresses it as Grid /
// TwoColumn / separate blocks.
//
// TWO MODES:
//   - sanitize(html)  → HTML string safe to store in the five
//                       richtext-permitted props (Text.body,
//                       TwoColumn.leftBody/rightBody,
//                       Accordion.items[].body, FAQ.items[].body).
//   - plainText(html) → prose-only string safe to store in ANY
//                       other string prop. HTML sent to those props
//                       renders LITERALLY with visible tags.
//
// ALLOWED VOCABULARY (Contract Part II "Rich text"):
//   Block:  p, h1-h6, ul, ol, li, blockquote, hr, pre
//   Inline: strong, em, u, s, a[href], code, br
//   Attr:   `style="text-align: (left|center|right|justify)"` on
//           block elements only. Everything else stripped.
//
// EXPLICITLY FORBIDDEN (per the contract):
//   <table>, <div>, <span>, <img>, <iframe>, <form>, <script>,
//   class=, id=, event handlers (onclick, onload, …), inline
//   styles other than text-align.
//
// <img> in richtext deserves a special note: images cannot live in
// richtext (per Contract Part II) and become separate Image blocks
// + assets[] entries. The mapper (Slice 5) does that promotion; the
// sanitiser here just drops the <img> so it doesn't leak through.
final class RichTextSanitizer
{
    /**
     * @var array<int, string> Tags whose content is preserved and re-wrapped/kept.
     */
    private const ALLOWED_TAGS = [
        'p',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li',
        'blockquote',
        'hr', 'br', 'pre',
        'strong', 'em', 'u', 's', 'a', 'code',
    ];

    /**
     * @var array<int, string> Tags where a `text-align` inline style is preserved.
     */
    private const BLOCK_TAGS = [
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote',
    ];

    /**
     * @var array<int, string> Tags whose children we drop AND whose text content is also dropped.
     */
    private const DROP_WITH_CONTENT = ['script', 'style', 'form', 'iframe', 'template', 'noscript'];

    /**
     * Down-convert HTML to the TipTap-supported subset. Empty input
     * or all-stripped output returns `""`.
     */
    public function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $doc = $this->parse($html);
        if ($doc === null) {
            return '';
        }

        // Walk the tree: replace disallowed elements with their
        // text content (or drop-with-content for scripts/styles),
        // strip disallowed attributes, normalise anchors.
        $root = $doc->getElementsByTagName('body')->item(0) ?? $doc->documentElement;
        if (! $root instanceof DOMElement) {
            return '';
        }
        $this->walk($root, $doc);

        return $this->serialise($doc, $root);
    }

    /**
     * Strip ALL markup. Any HTML sent to a plain-text prop renders
     * literally (tags visible). This method returns just the text
     * content with whitespace normalised.
     */
    public function plainText(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $doc = $this->parse($html);
        if ($doc === null) {
            return '';
        }
        // textContent walks the tree and yields a string; drop
        // script/style content first so it doesn't leak as text.
        foreach (self::DROP_WITH_CONTENT as $tag) {
            $nodes = iterator_to_array($doc->getElementsByTagName($tag));
            foreach ($nodes as $node) {
                if ($node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
        $text = $doc->textContent;

        // Normalise whitespace: collapse runs of spaces/newlines.
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function parse(string $html): ?DOMDocument
    {
        $doc = new DOMDocument;
        // libxml is chatty about HTML5 tags; suppress and check
        // return value instead. UTF-8 prologue avoids mojibake.
        libxml_use_internal_errors(true);
        $ok = $doc->loadHTML(
            '<?xml encoding="UTF-8"?><html><body>'.$html.'</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        return $ok ? $doc : null;
    }

    private function walk(DOMNode $node, DOMDocument $doc): void
    {
        // Snapshot children — the walk mutates the tree.
        $children = iterator_to_array($node->childNodes);
        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                    // Kill node and everything in it.
                    $node->removeChild($child);

                    continue;
                }

                // Recurse first so descendants are cleaned before we
                // decide what to do with this element.
                $this->walk($child, $doc);

                if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    // Disallowed tag: unwrap. Move children before
                    // this element, then delete the element itself.
                    // (<div><p>x</p></div> → <p>x</p>)
                    $grandchildren = iterator_to_array($child->childNodes);
                    foreach ($grandchildren as $gc) {
                        $node->insertBefore($gc, $child);
                    }
                    $node->removeChild($child);

                    continue;
                }

                // Allowed tag: strip attributes down to the allowed set.
                $this->stripAttributes($child, $tag);
            }
            // DOMText / DOMComment / DOMCdata pass through — we only
            // filter elements. Comments are removed by DOMDocument's
            // default HTML serialisation options later.
            if ($child instanceof \DOMComment && $child->parentNode !== null) {
                $child->parentNode->removeChild($child);
            }
        }
    }

    private function stripAttributes(DOMElement $el, string $tag): void
    {
        $attrs = iterator_to_array($el->attributes);
        foreach ($attrs as $attr) {
            $name = strtolower($attr->name);

            if ($tag === 'a' && $name === 'href') {
                $normalised = $this->normaliseHref((string) $attr->value);
                if ($normalised === null) {
                    // Unusable anchor — unwrap it (drop the <a>, keep
                    // the text). Done AFTER the walk step so we
                    // leave a marker for the parent to unwrap.
                    $el->removeAttribute($name);
                } else {
                    $el->setAttribute('href', $normalised);
                }

                continue;
            }

            if (in_array($tag, self::BLOCK_TAGS, true) && $name === 'style') {
                $align = $this->extractTextAlign((string) $attr->value);
                if ($align !== null) {
                    $el->setAttribute('style', "text-align: {$align}");
                } else {
                    $el->removeAttribute('style');
                }

                continue;
            }

            // Everything else stripped: class, id, style-on-inline,
            // event handlers, data-*, target, rel, etc.
            $el->removeAttribute($name);
        }

        // Anchor without href after normalisation: unwrap it.
        if ($tag === 'a' && ! $el->hasAttribute('href')) {
            $parent = $el->parentNode;
            if ($parent !== null) {
                $children = iterator_to_array($el->childNodes);
                foreach ($children as $c) {
                    $parent->insertBefore($c, $el);
                }
                $parent->removeChild($el);
            }
        }
    }

    private function normaliseHref(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || $href === '#') {
            return null;
        }

        // mailto: — preserve target, strip obfuscation like
        // HTML-entity-encoded chars (DOMDocument already decodes
        // most of these on parse). Bare mailto: with a hash-obscured
        // target is unusable.
        if (str_starts_with(strtolower($href), 'mailto:')) {
            $addr = substr($href, 7);
            if ($addr === '' || ! str_contains($addr, '@')) {
                return null;
            }

            return 'mailto:'.$addr;
        }

        // Absolute URLs (http/https): strip tracking params.
        if (preg_match('#^https?://#i', $href) === 1) {
            return $this->stripTrackingParams($href);
        }

        // Root-relative: keep as-is.
        if (str_starts_with($href, '/')) {
            return $href;
        }

        // In-page anchor: keep.
        if (str_starts_with($href, '#')) {
            return $href;
        }

        // Anything else (relative like "about.html", scheme-less
        // like "example.com") is unusable in the final site because
        // it resolves against a URL we don't own. Contract Part II:
        // "Anchors must be absolute (https://…) or root-relative
        // (/about). Never relative to the scraped site's path
        // structure."
        return null;
    }

    private function stripTrackingParams(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['query'])) {
            return $url;
        }
        parse_str($parts['query'], $params);
        // utm_*, fbclid, gclid, mc_*, _ga, ref, ref_src — the usual set.
        $filtered = [];
        foreach ($params as $k => $v) {
            if (! is_string($k)) {
                continue;
            }
            if (preg_match('/^(utm_|fbclid$|gclid$|mc_|_ga$|ref$|ref_src$)/i', $k) === 1) {
                continue;
            }
            $filtered[$k] = $v;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = $filtered === [] ? '' : '?'.http_build_query($filtered);
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return "{$scheme}://{$host}{$port}{$path}{$query}{$fragment}";
    }

    private function extractTextAlign(string $style): ?string
    {
        if (preg_match('/text-align\s*:\s*(left|center|right|justify)\b/i', $style, $m) === 1) {
            return strtolower($m[1]);
        }

        return null;
    }

    private function serialise(DOMDocument $doc, DOMElement $root): string
    {
        // saveHTML on individual children skips the outer <body>
        // wrapper we introduced during parse.
        $html = '';
        foreach ($root->childNodes as $child) {
            $chunk = $doc->saveHTML($child);
            if (is_string($chunk)) {
                $html .= $chunk;
            }
        }

        return trim($html);
    }
}
