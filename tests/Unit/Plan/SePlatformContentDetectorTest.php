<?php

declare(strict_types=1);

namespace Tests\Unit\Plan;

use App\Services\Plan\SePlatformContentDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Detector unit coverage. The three-signal rule is conservative by design;
// these tests pin both directions of the boundary:
//   - the two known true positives (SE Parents, Unsubscribe) trip every signal
//   - host classification correctly excludes tenant-reg + CDN media
//   - the vocab signal is load-bearing: link-density alone with zero vocab
//     stays KEEP (protects the curated-SE-links false-park case)
final class SePlatformContentDetectorTest extends TestCase
{
    // ─── real on-disk corpus: ground truth ──────────────────────────────
    //
    // Direct assertions against the actual chrome-free Firecrawl bodies
    // captured during INGEST. These two are the true positives the
    // investigation identified. If either of these stops firing, the
    // detector regressed against real data.

    #[Test]
    #[DataProvider('truePositiveCorpusBodies')]
    public function real_on_disk_se_platform_body_fires_the_detector(string $bodyFile, string $description): void
    {
        $path = storage_path('app/private/orgs/ngin-63620/scrapes/'.$bodyFile);
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, "corpus file missing: {$bodyFile}");
        $data = json_decode($raw, true);
        $this->assertIsArray($data);
        $markdown = $data['markdown'] ?? '';
        $this->assertIsString($markdown);

        $verdict = (new SePlatformContentDetector)->detect($markdown);

        $this->assertTrue(
            $verdict->is_se_platform,
            "{$description} body MUST trip the detector on real content. ".
            "Got: links={$verdict->se_platform_links}/{$verdict->total_outbound_links}, ".
            "ratio={$verdict->ratio}, vocab=".count($verdict->vocab_phrases_matched)
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function truePositiveCorpusBodies(): iterable
    {
        yield 'SportsEngine Parents' => [
            'ae333e87779efabe6a9c2327b0a8524c810ddd5b.json',
            'SportsEngine Parents (parent-help)',
        ];
        yield 'How To Unsubscribe' => [
            '8f2b72a312b6b0debdd445850bfa8c5c3b52c5c6.json',
            'How To Unsubscribe',
        ];
    }

    #[Test]
    #[DataProvider('trueNegativeCorpusBodies')]
    public function real_on_disk_org_body_does_not_fire_the_detector(string $bodyFile, string $description): void
    {
        $path = storage_path('app/private/orgs/ngin-63620/scrapes/'.$bodyFile);
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, "corpus file missing: {$bodyFile}");
        $data = json_decode($raw, true);
        $this->assertIsArray($data);
        $markdown = $data['markdown'] ?? '';
        $this->assertIsString($markdown);

        $verdict = (new SePlatformContentDetector)->detect($markdown);

        $this->assertFalse(
            $verdict->is_se_platform,
            "{$description} is real org content; detector MUST NOT fire. ".
            "Got: links={$verdict->se_platform_links}/{$verdict->total_outbound_links}, ".
            "ratio={$verdict->ratio}, vocab=".count($verdict->vocab_phrases_matched)
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function trueNegativeCorpusBodies(): iterable
    {
        yield 'About Us' => ['0c95764f2451659896ccee8d6781771deeca3e50.json', 'About Us'];
        yield 'Home' => ['4ef3d348e1a5db523dc9196110cb62b84baa3f76.json', 'Home (the SE app block is sub-page)'];
        yield 'Parents Portal' => ['96cf05aed5b36c987e2a3857160ff610cfbe3004.json', 'Parents Portal'];
        yield 'Our Board' => ['59ab39f258e55bad93aacddd8e6a90fce18c41c2.json', 'Our Board'];
        yield 'Our Facilities' => ['96f9021e074061e8bd5c4f6611729b1c01294b9e.json', 'Our Facilities'];
        yield 'TBird News' => ['5ed35cbc529cfa2c650fedea03eb4ef3f69bb8d8.json', 'TBird News'];
        yield 'Contact Us' => ['3e3c41f30d13bee024bb1e70bc969b60eda61105.json', 'CONTACT US'];
    }

    // ─── synthetic edge cases ───────────────────────────────────────────

    #[Test]
    public function se_parents_page_fingerprint_parks(): void
    {
        // Synthetic body mirroring the tbirdhoops SE Parents page shape:
        // SE-platform tutorial links everywhere, multiple SE-vocab phrases.
        $md = <<<'MD'
            ### Parents and Athletes

            Parents need to manage their SportsEngine accounts to get the most
            out of the Team Management Guide. [New to SportsEngine?](https://help.sportsengine.com/en/collections/3502726-for-parents)

            ### Essential How-To's

            [Adding an additional guardian](https://help.sportsengine.com/en/articles/6304039-how-to-add-guardians)
            [Adding a Mobile Phone](https://help.sportsengine.com/en/articles/6304668-how-to-update-profile-information)
            [Following a Team on the SportsEngine Mobile App](https://mobile-help.sportsengine.com/en/articles/8284930-how-to-find-and-follow-teams)
            [Reset My Login Password](https://help.sportsengine.com/en/articles/6310657-how-to-reset-my-login-password)

            Reset your password to view your MySE dashboard.
            MD;

        $verdict = (new SePlatformContentDetector)->detect($md);

        $this->assertTrue($verdict->is_se_platform);
        $this->assertGreaterThanOrEqual(SePlatformContentDetector::LINK_FLOOR, $verdict->se_platform_links);
        $this->assertGreaterThanOrEqual(SePlatformContentDetector::LINK_RATIO_MIN, $verdict->ratio);
        $this->assertGreaterThanOrEqual(SePlatformContentDetector::VOCAB_FLOOR, count($verdict->vocab_phrases_matched));
    }

    #[Test]
    public function unsubscribe_page_fingerprint_parks_despite_short_link_count(): void
    {
        // The tbirdhoops Unsubscribe page has exactly 3 outbound links and
        // 2 distinct vocab phrases — right at the floor on both. This test
        // pins that boundary so a future tightening doesn't silently lift it.
        $md = <<<'MD'
            # How to Unsubscribe From Organizational Notifications

            1. [Sign in](https://intercom.help/SportsEngine/en/articles/6304015-sign-in) and go to [Settings](https://my.sportngin.com/user/account/general).
            2. Click Communications Preferences.
            3. If you want to remove the org from a profile, click [HERE](https://intercom.help/SportsEngine/en/articles/6304798-cancel-membership).

            If you want to stop team RSVPs, click on your team under the SE Bar to update your notifications. (Tip: my.sportngin)
            MD;

        $verdict = (new SePlatformContentDetector)->detect($md);

        $this->assertTrue($verdict->is_se_platform);
        $this->assertSame(3, $verdict->se_platform_links);
        $this->assertSame(3, $verdict->total_outbound_links);
        $this->assertSame(1.0, $verdict->ratio);
        $this->assertGreaterThanOrEqual(2, count($verdict->vocab_phrases_matched));
    }

    // ─── the load-bearing vocab guard ──────────────────────────────────

    #[Test]
    public function curated_se_links_with_zero_vocab_stays_kept(): void
    {
        // CRITICAL guard: this is the false-park case the vocab signal exists
        // to prevent. An org-authored page that curates SE help links with
        // its own commentary — "click here", "this guide", "the app" — but
        // contains NONE of the SE-platform first-person vocabulary. Link
        // ratio and floor BOTH trip; only the vocab signal saves it.
        //
        // If a future maintainer collapses this to "just check links",
        // this test fails — that's the point.
        $md = <<<'MD'
            ## Helpful guides we recommend

            Below are a few resources our team has put together to help families
            get started. Click each link for the full guide.

            - [How to sign up](https://help.sportsengine.com/en/articles/x-sign-up): a friendly walkthrough.
            - [Setting up notifications](https://help.sportsengine.com/en/articles/y-notifications): the steps the app takes you through.
            - [Adding a guardian](https://help.sportsengine.com/en/articles/z-guardian): for families with two adults.
            - [Refresher on RSVPing](https://help.sportsengine.com/en/articles/w-rsvp): in case you forgot.

            Reach out to your coach for anything else.
            MD;

        $verdict = (new SePlatformContentDetector)->detect($md);

        $this->assertFalse(
            $verdict->is_se_platform,
            'A curated org page with high SE-link ratio MUST stay Kept — the vocab signal is what protects it. '
            .'If this fails, someone has weakened the three-signal rule.'
        );
        $this->assertGreaterThanOrEqual(SePlatformContentDetector::LINK_FLOOR, $verdict->se_platform_links, 'link floor satisfied');
        $this->assertGreaterThanOrEqual(SePlatformContentDetector::LINK_RATIO_MIN, $verdict->ratio, 'ratio satisfied');
        $this->assertLessThan(SePlatformContentDetector::VOCAB_FLOOR, count($verdict->vocab_phrases_matched), 'vocab below floor: this is what keeps the page Kept');
    }

    #[Test]
    public function low_link_count_stays_kept_even_with_high_ratio(): void
    {
        // 1 SE link, 1 total → 100% ratio. Floor not met → keep.
        $md = '[Sign in](https://help.sportsengine.com/en/articles/6304015-sign-in) to my.sportngin to update your MySE.';
        $verdict = (new SePlatformContentDetector)->detect($md);

        $this->assertFalse($verdict->is_se_platform, 'fewer than 3 SE links → floor protects against single-link false-park');
        $this->assertSame(1, $verdict->se_platform_links);
    }

    #[Test]
    public function low_ratio_stays_kept_even_with_many_se_links(): void
    {
        // 3 SE links, but mixed with many internal links → ratio fails.
        // Real org-Home pattern: a sidebar of internal navigation plus a
        // few SE help references.
        $md = '
            [Home](https://example.org/home) [News](https://example.org/news) [About](https://example.org/about)
            [Board](https://example.org/board) [Calendar](https://example.org/cal) [Photos](https://example.org/photos)
            [Apple App](https://itunes.apple.com/us/app/sport-ngin/id1)
            [Android App](https://play.google.com/store/apps/details?id=com.sportngin.android)
            [SE solutions](https://www.sportsengine.com/solutions/mobile/)
        ';
        $verdict = (new SePlatformContentDetector)->detect($md);

        $this->assertFalse($verdict->is_se_platform);
        $this->assertSame(3, $verdict->se_platform_links);
        $this->assertLessThan(SePlatformContentDetector::LINK_RATIO_MIN, $verdict->ratio);
    }

    // ─── host classifier ────────────────────────────────────────────────

    #[Test]
    public function tenant_registration_url_is_not_an_se_platform_link(): void
    {
        // <tenant>.sportngin.com/register/form/<id> is the org's OWN
        // registration on SE infrastructure — counting it as SE-platform
        // would penalise legitimate org pages.
        $d = new SePlatformContentDetector;
        $this->assertFalse($d->isSePlatformLink('https://tbirdhoops.sportngin.com/register/form/803926750'));
        $this->assertFalse($d->isSePlatformLink('https://www.stthomassoccer.com/register/form/12345'));
    }

    #[Test]
    public function cdn_attachment_url_is_not_an_se_platform_link(): void
    {
        // cdn[N].sportngin.com/attachments/* is the org's inline media on
        // SE's CDN — photos, banners, documents the org uploaded. Counting
        // these as SE-platform navigation links would pollute every body.
        $d = new SePlatformContentDetector;
        $this->assertFalse($d->isSePlatformLink('https://cdn3.sportngin.com/attachments/photo/abc/img_large.jpg'));
        $this->assertFalse($d->isSePlatformLink('https://cdn1.sportngin.com/attachments/banner_graphic/x/banner.jpg'));
        $this->assertFalse($d->isSePlatformLink('https://cdn4.sportngin.com/attachments/document/x/guide.pdf'));
    }

    #[Test]
    public function help_center_and_app_store_urls_are_se_platform_links(): void
    {
        $d = new SePlatformContentDetector;
        $this->assertTrue($d->isSePlatformLink('https://help.sportsengine.com/en/articles/6304039-how-to-add-guardians'));
        $this->assertTrue($d->isSePlatformLink('https://mobile-help.sportsengine.com/en/articles/8284930'));
        $this->assertTrue($d->isSePlatformLink('https://intercom.help/SportsEngine/en/articles/6304015'));
        $this->assertTrue($d->isSePlatformLink('https://my.sportngin.com/user/account/general'));
        $this->assertTrue($d->isSePlatformLink('https://www.sportsengine.com/solutions/mobile/'));
        $this->assertTrue($d->isSePlatformLink('https://itunes.apple.com/us/app/sport-ngin/id499597400'));
        $this->assertTrue($d->isSePlatformLink('https://play.google.com/store/apps/details?id=com.sportngin.android&hl=en'));
    }

    #[Test]
    public function org_own_internal_urls_are_not_se_platform_links(): void
    {
        $d = new SePlatformContentDetector;
        $this->assertFalse($d->isSePlatformLink('https://www.tbirdhoops.org/aboutus'));
        $this->assertFalse($d->isSePlatformLink('https://example.org/page/show/123'));
    }

    #[Test]
    public function vocab_phrases_are_distinct_and_case_insensitive(): void
    {
        // Repeated phrases score once; case is ignored. The vocab signal
        // is the count of DISTINCT phrases, not occurrences.
        $md = 'Manage your MySE. Manage your myse. MYSE again. team management guide. Team Management Guide.';
        $verdict = (new SePlatformContentDetector)->detect($md);

        // Distinct phrases matched: 'myse' + 'team management guide' = 2.
        $this->assertCount(2, $verdict->vocab_phrases_matched);
    }
}
