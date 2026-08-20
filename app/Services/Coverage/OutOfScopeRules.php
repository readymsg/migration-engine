<?php

declare(strict_types=1);

namespace App\Services\Coverage;

use App\Data\SourceElement;

// Deterministic classifier for source elements the product has SCOPED
// OUT of this version's migration. Runs AFTER captured / superseded /
// excluded checks in CoverageReconciler — so a captured element beats a
// scoping rule, a superseded element beats it, and an excluded (source-
// platform chrome) element beats it too.
//
// The point of OUT_OF_SCOPE is to separate DELIBERATE product-scoping
// decisions from DROPPED (unintentional capture failures). If we didn't
// separate them, ~60 scoped-out items on tbirdhoops would bury the
// real defects in DROPPED. But scoping must NOT become a second
// silent-loss channel — the rules below are deliberately narrow and
// each one names the platform feature that will eventually own the
// content.
//
// RULES:
//
//   RULE 1 — Sponsor strip (element-shape, works on any page)
//     Feature: Sponsors platform block.
//     Sponsors appear site-wide (Board + Our Facilities in tbirdhoops)
//     as small image-linked strips with a "Become a sponsor" CTA.
//     Element-shape match — does NOT require page context:
//       - image / prose whose URL contains /attachments/sponsor/
//       - image with alt starting "sponsored by"
//       - short prose containing the whole phrase "become a sponsor",
//         "interested in becoming a sponsor", or "want to participate
//         in your local community". Length cap prevents a paragraph
//         that MENTIONS sponsors from matching.
//
//   RULE 2 — News article internals (page-context)
//     Feature: NewsList / NewsRotator / PlatformNews.
//     Page context = news_landing if:
//       - page title contains a standalone "news" token, OR
//       - the page has ≥ 2 top-level Card blocks whose props.href
//         contains "/news_article/" (matches SE-style news landings)
//     On a news_landing page, any element still headed for DROPPED
//     becomes OUT_OF_SCOPE with feature=NewsList. The whole page is
//     "article previews" — the article title + short body of each
//     Card is captured; article internals (bylines, body paragraphs,
//     section headings, embedded registration links) are scoped out.
//
//   RULE 3 — Person/role directory internals (page-context)
//     Feature: Executives (Board) or Executives / TeamMembers (Contacts).
//     Page context = board_directory or contact_directory when the
//     page title contains a standalone board/contacts/directors/
//     executives/officers/contact-us token. On these pages, an about-
//     to-be-DROPPED element that matches PERSON/ROLE/CONTACT shape
//     (contact_detail kind, table, or short prose containing a closed
//     role vocabulary) becomes OUT_OF_SCOPE. Everything else stays in
//     DROPPED so real content loss on these pages still surfaces.
final class OutOfScopeRules
{
    /** @var array<int, string> role vocabulary — narrow, whole-word check inside short prose */
    private const ROLE_WORDS = [
        'president',
        'vice president',
        ' vp ',
        'treasurer',
        'secretary',
        'director',
        'coordinator',
        'chairperson',
        ' chair ',
        'commissioner',
        'league scheduler',
        'database administrator',
        'board member',
        'officer',
        'coach ',
    ];

    /** @var array<int, string> sponsor CTA whole phrases */
    private const SPONSOR_PHRASES = [
        'interested in becoming a sponsor',
        'become a sponsor',
        'want to participate in your local community',
    ];

    /**
     * @param  array<string, mixed>  $puckPayload
     * @return array{out_of_scope: bool, category: string, feature: string, reason: string}
     */
    public function classify(SourceElement $element, array $puckPayload, string $pageTitle): array
    {
        $notScoped = ['out_of_scope' => false, 'category' => '', 'feature' => '', 'reason' => ''];

        // RULE 1 — sponsor (element-shape, site-wide)
        if ($this->isSponsorElement($element)) {
            return [
                'out_of_scope' => true,
                'category' => 'sponsor strip',
                'feature' => 'Sponsors',
                'reason' => 'sponsor strip content — deliberately not migrated in this version; a Sponsors platform block will eventually own this content',
            ];
        }

        $context = $this->inferPageContext($puckPayload, $pageTitle);

        // RULE 2 — news article internals
        if ($context === 'news_landing') {
            return [
                'out_of_scope' => true,
                'category' => 'news article internal',
                'feature' => 'NewsList / NewsRotator',
                'reason' => 'element sits on a news landing page; article body content (bylines, paragraphs, section headings, embedded registration links) is deliberately not migrated in this version — a NewsList / NewsRotator platform block will eventually own this content',
            ];
        }

        // RULE 3 — board / contact directory internals
        if ($context === 'board_directory' || $context === 'contact_directory') {
            if ($this->looksLikePersonRoleData($element)) {
                $isBoard = $context === 'board_directory';
                $feature = $isBoard ? 'Executives' : 'Executives / TeamMembers';
                $category = $isBoard ? 'board member card' : 'contact directory card';

                return [
                    'out_of_scope' => true,
                    'category' => $category,
                    'feature' => $feature,
                    'reason' => "element sits on a {$context} page and matches person/role/contact shape; deliberately not migrated in this version — a {$feature} platform block will eventually own this content",
                ];
            }
        }

        return $notScoped;
    }

    /**
     * @param  array<string, mixed>  $puckPayload
     */
    public function inferPageContext(array $puckPayload, string $pageTitle): ?string
    {
        $titleLower = mb_strtolower(trim($pageTitle));
        $content = is_array($puckPayload['content'] ?? null) ? $puckPayload['content'] : [];

        // News landing detection is deliberately TIGHT so Home pages
        // that feature 1–2 news Cards among other content DON'T get
        // classified as news landings (that would misclassify legitimate
        // Home content).
        //   - PRIMARY signal: page title contains the whole-word
        //     "news" token.
        //   - FALLBACK signal (shape): a page is a news landing only
        //     if ≥ 3 top-level Cards target /news_article/ AND those
        //     news Cards are the MAJORITY of top-level Card blocks.
        if (preg_match('/\bnews\b/', $titleLower) === 1) {
            return 'news_landing';
        }
        $totalCards = 0;
        $newsCards = 0;
        foreach ($content as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'Card') {
                continue;
            }
            $totalCards++;
            $href = $block['props']['href'] ?? '';
            if (is_string($href) && str_contains(mb_strtolower($href), '/news_article/')) {
                $newsCards++;
            }
        }
        if ($newsCards >= 3 && $newsCards * 2 >= $totalCards) {
            return 'news_landing';
        }

        // Contact directory.
        if (preg_match('/\b(contacts?|contact us|directory)\b/', $titleLower) === 1) {
            return 'contact_directory';
        }

        // Board.
        if (preg_match('/\b(board|directors|executives|officers)\b/', $titleLower) === 1) {
            return 'board_directory';
        }

        return null;
    }

    /**
     * @return array<int, array{rule: string, feature: string, description: string}>
     */
    public function ruleSummary(): array
    {
        return [
            [
                'rule' => 'sponsor strip',
                'feature' => 'Sponsors',
                'description' => 'Sponsor logos, sponsor CTA prose, and "Become a sponsor" placeholders. Site-wide (Board / Facilities in tbirdhoops). A Sponsors platform block will eventually own this content.',
            ],
            [
                'rule' => 'news article internal',
                'feature' => 'NewsList / NewsRotator',
                'description' => 'On a news landing page, article body content — bylines, paragraphs, section headings, embedded registration links. Article titles + short summaries stay CAPTURED via the news Card blocks. NewsList / NewsRotator will eventually own this content.',
            ],
            [
                'rule' => 'board member card',
                'feature' => 'Executives',
                'description' => 'On a board page, person/role/contact-shape content — role vocabulary in short prose, contact_detail mailtos, position/person/phone tables. The Executives platform block will eventually own this content.',
            ],
            [
                'rule' => 'contact directory card',
                'feature' => 'Executives / TeamMembers',
                'description' => 'On a contacts page, person/role/contact-shape content — role-routed mailtos, position tables, short role prose. The Executives / TeamMembers platform block will eventually own this content.',
            ],
        ];
    }

    private function isSponsorElement(SourceElement $element): bool
    {
        $textLower = mb_strtolower($element->content);

        // Image / link asset URL under /attachments/sponsor/.
        if (str_contains($textLower, '/attachments/sponsor/')) {
            return true;
        }
        // Alt attribute or prose that starts with "sponsored by".
        if (str_contains($textLower, 'sponsored by')) {
            return true;
        }
        // Sponsor CTA whole-phrase match (case-insensitive) on SHORT
        // prose only. A long paragraph that mentions sponsors is real
        // content, not a CTA — length cap keeps the rule tight.
        if ($element->kind === 'prose' || $element->kind === 'heading') {
            $normalised = mb_strtolower(trim($element->content));
            if (mb_strlen($normalised) < 250) {
                foreach (self::SPONSOR_PHRASES as $phrase) {
                    if (str_contains($normalised, $phrase)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function looksLikePersonRoleData(SourceElement $element): bool
    {
        // Any contact_detail on a directory page is scoped out.
        if ($element->kind === 'contact_detail') {
            return true;
        }
        // A whole table on a directory page (Position | Person | Phone …).
        if ($element->kind === 'table') {
            return true;
        }
        // Short prose (< 100 chars) containing a role-vocabulary word.
        // Long paragraphs are NOT covered — they're likely real dropped
        // copy that happens to name a role.
        if ($element->kind === 'prose' || $element->kind === 'heading') {
            $lower = mb_strtolower(trim($element->content));
            if (mb_strlen($lower) === 0 || mb_strlen($lower) > 100) {
                return false;
            }
            $padded = ' '.$lower.' ';
            foreach (self::ROLE_WORDS as $word) {
                if (str_contains($padded, $word)) {
                    return true;
                }
            }
        }

        return false;
    }
}
