<?php

declare(strict_types=1);

namespace Tests\Unit\ContractEmitter;

use App\Services\ContractEmitter\RichTextSanitizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Pins the sanitiser against the silent-loss trap the contract Part
// II "Rich text" explicitly warns about — every disallowed markup
// pattern below is one TipTap would silently drop on the first
// admin edit, so we drop it here first.
final class RichTextSanitizerTest extends TestCase
{
    private RichTextSanitizer $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = new RichTextSanitizer;
    }

    // ─── ALLOWED VOCABULARY passes through untouched ─────────────────────

    #[Test]
    public function paragraph_with_supported_inline_marks_passes_through(): void
    {
        $out = $this->s->sanitize('<p>Hello <strong>brave</strong> <em>new</em> <u>world</u> <s>maybe</s></p>');
        $this->assertSame('<p>Hello <strong>brave</strong> <em>new</em> <u>world</u> <s>maybe</s></p>', $out);
    }

    #[Test]
    public function headings_h1_through_h6_are_preserved(): void
    {
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
            $out = $this->s->sanitize("<{$tag}>Title</{$tag}>");
            $this->assertSame("<{$tag}>Title</{$tag}>", $out);
        }
    }

    #[Test]
    public function lists_and_blockquote_are_preserved(): void
    {
        $out = $this->s->sanitize('<ul><li>one</li><li>two</li></ul>');
        $this->assertSame('<ul><li>one</li><li>two</li></ul>', $out);

        $out = $this->s->sanitize('<ol><li>a</li></ol>');
        $this->assertSame('<ol><li>a</li></ol>', $out);

        $out = $this->s->sanitize('<blockquote>Quoted.</blockquote>');
        $this->assertSame('<blockquote>Quoted.</blockquote>', $out);
    }

    #[Test]
    public function code_and_pre_are_preserved(): void
    {
        $this->assertSame('<code>x</code>', $this->s->sanitize('<code>x</code>'));
        $this->assertSame('<pre>a</pre>', $this->s->sanitize('<pre>a</pre>'));
    }

    #[Test]
    public function hr_and_br_are_preserved(): void
    {
        $this->assertStringContainsString('<hr', $this->s->sanitize('<hr>'));
        $this->assertStringContainsString('<br', $this->s->sanitize('<br>'));
    }

    #[Test]
    public function text_align_style_is_preserved_on_block_elements(): void
    {
        $out = $this->s->sanitize('<p style="text-align: center">Centred</p>');
        $this->assertStringContainsString('text-align: center', $out);
        $this->assertStringContainsString('<p style=', $out);
    }

    // ─── SILENT-LOSS TRAP — the contract's warning shape ─────────────────

    #[Test]
    public function table_layout_is_destroyed_not_passed_through(): void
    {
        // The exact contract Part II warning shape — <table> renders
        // then vanishes on first edit. We destroy it here so the
        // reviewer sees the drop, not later after publish.
        $html = '<table><tr><td>Cell A</td><td>Cell B</td></tr></table>';
        $out = $this->s->sanitize($html);
        $this->assertStringNotContainsString('<table', $out);
        $this->assertStringNotContainsString('<tr', $out);
        $this->assertStringNotContainsString('<td', $out);
        // Cell text content is preserved (unwrap semantics) — the
        // mapper's job is to re-express table layout as Grid /
        // TwoColumn / separate blocks; the sanitiser here does
        // NOT invent structure, just strips the disallowed tags.
        $this->assertStringContainsString('Cell A', $out);
        $this->assertStringContainsString('Cell B', $out);
    }

    #[Test]
    public function div_and_span_are_unwrapped(): void
    {
        $out = $this->s->sanitize('<div><p>Wrapped</p></div>');
        $this->assertSame('<p>Wrapped</p>', $out);

        $out = $this->s->sanitize('<p>text <span>with span</span> inline</p>');
        $this->assertSame('<p>text with span inline</p>', $out);
    }

    #[Test]
    public function images_are_dropped_from_richtext(): void
    {
        // Contract Part II: "Images cannot live inside rich text.
        // A picture in a scraped paragraph becomes a separate Image
        // block plus an assets[] entry." Sanitiser drops; mapper
        // promotes.
        $out = $this->s->sanitize('<p>Before <img src="a.jpg" alt="A"> after</p>');
        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringContainsString('Before', $out);
        $this->assertStringContainsString('after', $out);
    }

    #[Test]
    public function iframes_are_dropped(): void
    {
        $out = $this->s->sanitize('<p>Video below</p><iframe src="https://youtube.com/embed/x"></iframe>');
        $this->assertStringNotContainsString('<iframe', $out);
        $this->assertStringNotContainsString('youtube.com', $out);
    }

    #[Test]
    public function scripts_are_dropped_with_content(): void
    {
        // Script CONTENT must not leak as text (unlike unwrap semantics).
        $out = $this->s->sanitize('<p>Before</p><script>alert("x")</script><p>After</p>');
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringContainsString('Before', $out);
        $this->assertStringContainsString('After', $out);
    }

    #[Test]
    public function style_tags_are_dropped_with_content(): void
    {
        $out = $this->s->sanitize('<style>p { color: red }</style><p>Hi</p>');
        $this->assertStringNotContainsString('<style', $out);
        $this->assertStringNotContainsString('color: red', $out);
        $this->assertStringContainsString('Hi', $out);
    }

    #[Test]
    public function forms_are_dropped_with_content(): void
    {
        $out = $this->s->sanitize('<form><input name="email"></form><p>After</p>');
        $this->assertStringNotContainsString('<form', $out);
        $this->assertStringNotContainsString('<input', $out);
        $this->assertStringContainsString('After', $out);
    }

    #[Test]
    public function inline_styles_other_than_text_align_are_stripped(): void
    {
        $out = $this->s->sanitize('<p style="color: red; font-size: 40px">Coloured</p>');
        $this->assertStringNotContainsString('color: red', $out);
        $this->assertStringNotContainsString('font-size', $out);
        $this->assertSame('<p>Coloured</p>', $out);
    }

    #[Test]
    public function class_and_id_are_stripped(): void
    {
        $out = $this->s->sanitize('<p class="lead" id="intro">Text</p>');
        $this->assertSame('<p>Text</p>', $out);
    }

    #[Test]
    public function event_handlers_are_stripped(): void
    {
        $out = $this->s->sanitize('<a href="/about" onclick="alert(1)">Link</a>');
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringContainsString('href="/about"', $out);
    }

    // ─── ANCHOR NORMALISATION ────────────────────────────────────────────

    #[Test]
    public function absolute_https_anchor_survives_intact(): void
    {
        $out = $this->s->sanitize('<a href="https://example.com/about">About</a>');
        $this->assertStringContainsString('href="https://example.com/about"', $out);
    }

    #[Test]
    public function root_relative_anchor_survives_intact(): void
    {
        $out = $this->s->sanitize('<a href="/about">About</a>');
        $this->assertStringContainsString('href="/about"', $out);
    }

    #[Test]
    public function bare_relative_anchor_is_unwrapped(): void
    {
        // Contract Part II: "Anchors must be absolute (https://…) or
        // root-relative (/about). Never relative to the scraped
        // site's path structure." A relative anchor points at the
        // OLD site's path structure, breaking when it goes away.
        $out = $this->s->sanitize('<a href="about.html">About</a>');
        $this->assertStringNotContainsString('<a', $out);
        $this->assertStringContainsString('About', $out);
    }

    #[Test]
    public function hash_only_anchor_is_unwrapped(): void
    {
        $out = $this->s->sanitize('<a href="#">Placeholder</a>');
        $this->assertStringNotContainsString('<a', $out);
        $this->assertStringContainsString('Placeholder', $out);
    }

    #[Test]
    public function in_page_hash_anchor_survives(): void
    {
        $out = $this->s->sanitize('<a href="#section">Jump</a>');
        $this->assertStringContainsString('href="#section"', $out);
    }

    #[Test]
    public function mailto_survives(): void
    {
        $out = $this->s->sanitize('<a href="mailto:info@example.com">Email</a>');
        $this->assertStringContainsString('href="mailto:info@example.com"', $out);
    }

    #[Test]
    public function tracking_params_are_stripped_from_anchor(): void
    {
        $out = $this->s->sanitize('<a href="https://example.com/register?utm_source=x&utm_medium=y&code=abc">Register</a>');
        $this->assertStringNotContainsString('utm_source', $out);
        $this->assertStringNotContainsString('utm_medium', $out);
        // Non-tracking params must survive.
        $this->assertStringContainsString('code=abc', $out);
    }

    #[Test]
    public function target_and_rel_attributes_are_stripped(): void
    {
        // Even legitimate-looking `target="_blank"` is stripped —
        // the contract doesn't declare it in the anchor vocabulary,
        // so any storage-side surprise there is a silent-drop risk.
        $out = $this->s->sanitize('<a href="/x" target="_blank" rel="noopener">Link</a>');
        $this->assertStringNotContainsString('target', $out);
        $this->assertStringNotContainsString('rel=', $out);
    }

    // ─── PLAIN-TEXT MODE ─────────────────────────────────────────────────

    #[Test]
    public function plain_text_strips_all_markup(): void
    {
        $out = $this->s->plainText('<p>Hello <strong>world</strong> from <em>Home</em>.</p>');
        $this->assertSame('Hello world from Home.', $out);
    }

    #[Test]
    public function plain_text_collapses_whitespace(): void
    {
        $out = $this->s->plainText("<p>Hello\n\n   world  </p>");
        $this->assertSame('Hello world', $out);
    }

    #[Test]
    public function plain_text_drops_script_content(): void
    {
        $out = $this->s->plainText('Before<script>alert(1)</script>after');
        $this->assertSame('Beforeafter', $out);
    }

    // ─── EDGE CASES ──────────────────────────────────────────────────────

    #[Test]
    public function empty_input_returns_empty_string(): void
    {
        $this->assertSame('', $this->s->sanitize(''));
        $this->assertSame('', $this->s->sanitize('   '));
        $this->assertSame('', $this->s->plainText(''));
    }

    #[Test]
    public function nested_disallowed_wrappers_all_unwrap(): void
    {
        // <div><section><article><p>x</p></article></section></div>
        // → <p>x</p>. All three wrappers dropped.
        $out = $this->s->sanitize('<div><section><article><p>x</p></article></section></div>');
        $this->assertSame('<p>x</p>', $out);
    }

    #[Test]
    public function multiple_top_level_paragraphs_are_preserved(): void
    {
        $out = $this->s->sanitize('<p>One</p><p>Two</p>');
        $this->assertSame('<p>One</p><p>Two</p>', $out);
    }
}
