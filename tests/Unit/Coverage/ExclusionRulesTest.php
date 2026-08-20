<?php

declare(strict_types=1);

namespace Tests\Unit\Coverage;

use App\Data\SourceElement;
use App\Services\Coverage\ExclusionRules;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Discipline: exclusion rules must be TIGHT. Over-exclusion is silent
// loss disguised as intentional. These tests pin real content on both
// sides of every rule — every fixture is either genuinely SE-platform
// chrome (must exclude) OR genuinely-org content (must NOT exclude).
final class ExclusionRulesTest extends TestCase
{
    private function rules(): ExclusionRules
    {
        return new ExclusionRules;
    }

    #[Test]
    public function se_platform_chrome_urls_are_excluded(): void
    {
        $cases = [
            'https://tbirdhoops.sportsengine-prelive.com/home',
            'https://itunes.apple.com/us/app/sport-ngin/id499597400',
            'https://play.google.com/store/apps/details?id=com.sportngin.android&hl=en',
            'https://www.sportsengine.com/solutions/team-sports',
            'https://my.sportngin.com/user/register',
            'https://tbirdhoops.sportsengine.com/user_sessions',
        ];
        foreach ($cases as $url) {
            $r = $this->rules()->classify(new SourceElement('link', $url, "[x]({$url})"));
            $this->assertTrue($r['excluded'], "must exclude {$url}");
            $this->assertSame('SE-platform chrome URL', $r['rule']);
        }
    }

    #[Test]
    public function real_help_and_signup_org_urls_are_no_t_excluded_by_platform_rule(): void
    {
        // help.sportsengine.com is org-linkable — orgs DO link to help
        // articles legitimately (langdondiamonds' Coaches page does).
        // Same for /register/form/... which is org-managed registration.
        $cases = [
            'https://help.sportsengine.com/en/articles/12345-coach-guide',
            'https://tbirdhoops.sportngin.com/register/form/770430566',
        ];
        foreach ($cases as $url) {
            $r = $this->rules()->classify(new SourceElement('link', $url, "[x]({$url})"));
            $this->assertFalse(
                $r['excluded'],
                "must NOT exclude {$url} — this is org-linkable content, not SE-chrome"
            );
        }
    }

    #[Test]
    public function same_site_nav_duplicates_are_excluded(): void
    {
        $r = $this->rules()->classify(
            new SourceElement('link', 'https://www.tbirdhoops.org/home', '[Home](https://www.tbirdhoops.org/home)'),
            sourceOrigin: 'https://www.tbirdhoops.org/',
        );
        $this->assertTrue($r['excluded']);
        $this->assertSame('same-site chrome duplicate', $r['rule']);
    }

    #[Test]
    public function same_site_deep_links_are_no_t_treated_as_chrome(): void
    {
        // A link back into the same site's deep content (article /
        // page/show/... URLs) is REAL content, not nav chrome.
        $deep = 'https://www.tbirdhoops.org/news_article/show/1351261?referrer_id=7188115';
        $r = $this->rules()->classify(
            new SourceElement('link', $deep, "[x]({$deep})"),
            sourceOrigin: 'https://www.tbirdhoops.org/',
        );
        $this->assertFalse($r['excluded'], 'same-site article / deep link must NOT match chrome rule');
    }

    #[Test]
    public function skip_nav_anchors_are_excluded(): void
    {
        $r = $this->rules()->classify(new SourceElement('link', '#yieldContent', '[skip](#yieldContent)'));
        $this->assertTrue($r['excluded']);
        $this->assertSame('skip-nav / anchor-only URL', $r['rule']);
    }

    #[Test]
    public function legal_boilerplate_prose_is_excluded_but_only_whole_line_match(): void
    {
        $ex = $this->rules()->classify(new SourceElement('prose', 'Privacy Policy', 'Privacy Policy'));
        $this->assertTrue($ex['excluded']);
        $this->assertSame('legal / unsubscribe boilerplate', $ex['rule']);

        // Prose that MENTIONS the phrase inside larger content must NOT
        // be excluded — that's real content that happens to reference
        // policy.
        $notEx = $this->rules()->classify(new SourceElement(
            'prose',
            'Our privacy policy for the 2026 season is now available.',
            'Our privacy policy for the 2026 season is now available.',
        ));
        $this->assertFalse($notEx['excluded']);
    }

    #[Test]
    public function widget_artefact_countdown_labels_are_excluded(): void
    {
        // Decorated numeric forms — the actual shape tbirdhoops
        // captures for countdown widgets.
        foreach (['Days**0**', 'Hours**0**', 'Minutes**0**', '**0** Days', '**0**Days'] as $text) {
            $r = $this->rules()->classify(new SourceElement('prose', $text, $text));
            $this->assertTrue($r['excluded'], "must exclude countdown fragment '{$text}'");
            $this->assertSame('stale live-widget artefact', $r['rule']);
        }

        // Standalone unit label with no number — also chrome.
        $r = $this->rules()->classify(new SourceElement('prose', 'Seconds', 'Seconds'));
        $this->assertTrue($r['excluded']);
        $this->assertSame('stale live-widget artefact', $r['rule']);
    }

    #[Test]
    public function widget_artefact_bare_timestamp_is_excluded(): void
    {
        foreach (['00:00', '1:23', '12:34'] as $text) {
            $r = $this->rules()->classify(new SourceElement('prose', $text, $text));
            $this->assertTrue($r['excluded'], "must exclude bare timestamp '{$text}'");
            $this->assertSame('stale live-widget artefact', $r['rule']);
        }
    }

    #[Test]
    public function widget_artefact_video_player_chrome_is_excluded(): void
    {
        foreach (['StopPlay', 'stopplay', 'About JW Player 6.12.4956', 'About JW Player'] as $text) {
            $r = $this->rules()->classify(new SourceElement('prose', $text, $text));
            $this->assertTrue($r['excluded'], "must exclude video-player chrome '{$text}'");
            $this->assertSame('stale live-widget artefact', $r['rule']);
        }
    }

    #[Test]
    public function widget_artefact_arrow_wrapped_video_chrome_is_excluded(): void
    {
        // The tbirdhoops Home scrape captures a JW Player control
        // fragment as "←StopPlay→" — the arrows are Unicode decorators
        // wrapping the widget label. Whole-line normalisation strips
        // OUTER decorator glyphs so the whole-line pattern still
        // matches.
        $text = '←StopPlay→';
        $r = $this->rules()->classify(new SourceElement('prose', $text, $text));
        $this->assertTrue($r['excluded'], "must exclude arrow-wrapped video chrome '{$text}'");
        $this->assertSame('stale live-widget artefact', $r['rule']);
    }

    #[Test]
    public function widget_artefact_rule_does_no_t_over_match_real_content(): void
    {
        // Every one of these MUST stay non-excluded — they are real
        // org content that happens to contain a time or a unit-label
        // word. If any fires the widget-artefact rule, real content
        // is being silently discarded and this test catches it.
        $cases = [
            'Practice runs 6:30 to 8:00 on Tuesdays',
            'We had a great season — 20 hours of practice a week',
            'Doors open at 7:00 PM',
            'Sessions run for 2 hours',
            'Days available: Monday, Wednesday, Friday',
            'Registration closes in 3 days',
            'Play Ball!',                       // contains "play" but is a sentence
            'The seconds ticked down',          // "seconds" is not standalone
            'Registration → Sign up here!',     // arrow INSIDE prose must not trigger widget rule
            'Season → Fall 2026',               // arrow inside sentence
        ];
        foreach ($cases as $text) {
            $r = $this->rules()->classify(new SourceElement('prose', $text, $text));
            $this->assertFalse(
                $r['excluded'],
                "must NOT exclude real content: '{$text}' (matched rule='{$r['rule']}')"
            );
        }
    }

    #[Test]
    public function widget_artefact_rule_appears_in_rule_summary(): void
    {
        $rules = $this->rules()->ruleSummary();
        $names = array_column($rules, 'rule');
        $this->assertContains('stale live-widget artefact', $names);
    }
}
