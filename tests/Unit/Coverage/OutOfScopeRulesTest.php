<?php

declare(strict_types=1);

namespace Tests\Unit\Coverage;

use App\Data\SourceElement;
use App\Services\Coverage\OutOfScopeRules;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Discipline mirror of ExclusionRulesTest — rules must be tight.
// Every fixture is either genuinely scoped-out content (must fire the
// rule) OR genuinely-org content on a scoped-context page (must NOT
// fire). If the rules fire on real content, the scoping channel
// becomes a silent-loss disguise — the very thing OUT_OF_SCOPE
// exists to distinguish from DROPPED.
final class OutOfScopeRulesTest extends TestCase
{
    private function rules(): OutOfScopeRules
    {
        return new OutOfScopeRules;
    }

    /** @return array<string, mixed> */
    private function payloadWithBlocks(array $blocks, string $title = ''): array
    {
        return [
            'content' => $blocks,
            'root' => $title === '' ? [] : ['title' => $title],
            'zones' => [],
        ];
    }

    #[Test]
    public function sponsor_strip_urls_are_out_of_scope_on_any_page(): void
    {
        $cases = [
            'https://cdn3.sportngin.com/attachments/sponsor/22ec-188765262/sponsorLogo_basketball_element_view.png',
            'https://cdn1.sportngin.com/attachments/sponsor/d5a0-204690121/Store-Logo-DicksSportingGoods_element_view.png',
        ];
        $anyPayload = $this->payloadWithBlocks([]);
        foreach ($cases as $url) {
            $r = $this->rules()->classify(new SourceElement('image', $url, "![]({$url})"), $anyPayload, 'Some Random Page');
            $this->assertTrue($r['out_of_scope'], "must be out-of-scope: {$url}");
            $this->assertSame('sponsor strip', $r['category']);
            $this->assertSame('Sponsors', $r['feature']);
        }
    }

    #[Test]
    public function sponsor_cta_prose_is_out_of_scope(): void
    {
        $cases = [
            'Interested in becoming a sponsor of Lakota Thunderbird Youth Basketball?',
            'Want to participate in your local community? Become a sponsor for Lakota Thunderbird Youth Basketball and support youth in your area.',
            'Become a sponsor',
        ];
        foreach ($cases as $text) {
            $r = $this->rules()->classify(new SourceElement('prose', $text, $text), $this->payloadWithBlocks([]), 'Any Page');
            $this->assertTrue($r['out_of_scope'], "must be out-of-scope: '{$text}'");
            $this->assertSame('sponsor strip', $r['category']);
        }
    }

    #[Test]
    public function long_paragraph_mentioning_sponsors_stays_in_scope(): void
    {
        // A real content paragraph that mentions sponsors is org content,
        // NOT a CTA. Length cap keeps sponsor rule from swallowing it.
        $text = str_repeat('Our sponsors have helped fund uniforms and equipment for our players over many seasons. ', 4);
        $r = $this->rules()->classify(new SourceElement('prose', $text, $text), $this->payloadWithBlocks([]), 'Any Page');
        $this->assertFalse($r['out_of_scope'], 'long org paragraph mentioning sponsors must NOT be scoped out');
    }

    #[Test]
    public function news_landing_by_title_scopes_out_prose(): void
    {
        // Page title contains "News" — context is news_landing. Any
        // element that would otherwise be DROPPED becomes OUT_OF_SCOPE.
        $r = $this->rules()->classify(
            new SourceElement('prose', 'By Michael Lewis05/23/2026, 11:00pm EDT', 'By Michael Lewis05/23/2026, 11:00pm EDT'),
            $this->payloadWithBlocks([]),
            'TBird News',
        );
        $this->assertTrue($r['out_of_scope']);
        $this->assertSame('news article internal', $r['category']);
        $this->assertStringContainsString('NewsList', $r['feature']);
    }

    #[Test]
    public function news_landing_by_card_href_scopes_out_prose(): void
    {
        // Shape-based fallback: title doesn't say "news", but Puck
        // content has ≥ 3 Card blocks whose href goes to /news_article/
        // AND those are the majority of Cards on the page.
        $payload = $this->payloadWithBlocks([
            ['type' => 'Card', 'props' => ['title' => 'Article 1', 'href' => 'https://x/news_article/show/1']],
            ['type' => 'Card', 'props' => ['title' => 'Article 2', 'href' => 'https://x/news_article/show/2']],
            ['type' => 'Card', 'props' => ['title' => 'Article 3', 'href' => 'https://x/news_article/show/3']],
        ], 'Latest');
        $r = $this->rules()->classify(
            new SourceElement('prose', 'Article body paragraph here', 'Article body paragraph here'),
            $payload,
            'Latest',
        );
        $this->assertTrue($r['out_of_scope']);
        $this->assertSame('news article internal', $r['category']);
    }

    #[Test]
    public function home_page_featuring_two_news_cards_is_no_t_classified_as_news_landing(): void
    {
        // 2 news_article Cards among other Home content → NOT a news
        // landing. Home content that block-fill dropped stays in
        // DROPPED, not swallowed as "news article internal".
        $payload = $this->payloadWithBlocks([
            ['type' => 'Hero', 'props' => ['heading' => 'Welcome']],
            ['type' => 'Card', 'props' => ['title' => 'Featured News 1', 'href' => 'https://x/news_article/show/1']],
            ['type' => 'Card', 'props' => ['title' => 'Featured News 2', 'href' => 'https://x/news_article/show/2']],
            ['type' => 'Card', 'props' => ['title' => 'Registration', 'href' => 'https://x/register']],
            ['type' => 'Card', 'props' => ['title' => 'Calendar', 'href' => 'https://x/calendar']],
        ], 'Home');
        $r = $this->rules()->classify(
            new SourceElement('link', 'https://example.com/x', '[Home CTA](https://example.com/x)'),
            $payload,
            'Home',
        );
        $this->assertFalse(
            $r['out_of_scope'],
            'Home content must NOT be swallowed as news-article-internal just because Home features 2 news Cards',
        );
    }

    #[Test]
    public function board_page_scopes_out_role_prose_and_contact_details(): void
    {
        $boardPayload = $this->payloadWithBlocks([], 'Our Board');
        // Short role prose → out-of-scope
        $r1 = $this->rules()->classify(
            new SourceElement('prose', 'Scott Whitenack — President', 'Scott Whitenack — President'),
            $boardPayload,
            'Our Board',
        );
        $this->assertTrue($r1['out_of_scope']);
        $this->assertSame('board member card', $r1['category']);
        $this->assertSame('Executives', $r1['feature']);
        // Contact detail → out-of-scope
        $r2 = $this->rules()->classify(
            new SourceElement('contact_detail', 'president@tbirdhoops.org', '[Scott](mailto:president@tbirdhoops.org)'),
            $boardPayload,
            'Our Board',
        );
        $this->assertTrue($r2['out_of_scope']);
    }

    #[Test]
    public function board_page_lon_g_paragraph_stay_s_in_scope(): void
    {
        // A real dropped-org paragraph on a Board page must NOT become
        // OUT_OF_SCOPE — long prose is not covered by the person/role
        // shape check.
        $long = str_repeat('The board oversees the strategy of the organization and reviews program-level decisions. ', 3);
        $r = $this->rules()->classify(
            new SourceElement('prose', $long, $long),
            $this->payloadWithBlocks([], 'Our Board'),
            'Our Board',
        );
        $this->assertFalse($r['out_of_scope'], 'long org paragraph on Board page must stay in DROPPED');
    }

    #[Test]
    public function contacts_page_scopes_out_role_routed_mailtos(): void
    {
        $r = $this->rules()->classify(
            new SourceElement('contact_detail', 'bradleyawagner@gmail.com', '[Brad Wagner](mailto:bradleyawagner@gmail.com?subject=3rd%20Grade%20Inquiry)'),
            $this->payloadWithBlocks([], 'Contacts'),
            'Contacts',
        );
        $this->assertTrue($r['out_of_scope']);
        $this->assertSame('contact directory card', $r['category']);
        $this->assertStringContainsString('Executives', $r['feature']);
    }

    #[Test]
    public function non_directory_page_does_no_t_scope_out_role_prose(): void
    {
        // Page title = "About Us" — no board/contacts/news context.
        // Even a role-shaped prose line stays IN SCOPE (it'll DROP
        // instead if not captured).
        $r = $this->rules()->classify(
            new SourceElement('prose', 'Scott Whitenack — President', 'Scott Whitenack — President'),
            $this->payloadWithBlocks([], 'About Us'),
            'About Us',
        );
        $this->assertFalse($r['out_of_scope'], 'role-shaped prose off a directory page is NOT scoped out');
    }

    #[Test]
    public function every_rule_appears_in_rule_summary(): void
    {
        $rules = $this->rules()->ruleSummary();
        $names = array_column($rules, 'rule');
        $this->assertContains('sponsor strip', $names);
        $this->assertContains('news article internal', $names);
        $this->assertContains('board member card', $names);
        $this->assertContains('contact directory card', $names);
    }
}
