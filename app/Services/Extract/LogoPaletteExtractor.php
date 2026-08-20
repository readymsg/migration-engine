<?php

declare(strict_types=1);

namespace App\Services\Extract;

// Deterministic palette extractor. Reads a rehosted logo's raw bytes,
// decodes via GD, samples pixels, and returns 3–5 color tokens:
// primary, secondary, accent (optional), background, text.
//
// WHY THIS EXISTS: `Brand.palette` was always `[]` under the
// BrandExtractor.php:36 TODO ("extract from theme.css / inline
// <style> if we ever need it"). The preview then rendered whatever
// `GlobalStyleBrief.palette` an Opus call GUESSED — non-deterministic
// across runs and, empirically on tbirdhoops, wrong (#1F3A93 dark
// blue vs the club's actual red + black identity). This service
// resolves the TODO via the image path instead: a naive quantised
// histogram over the logo's actual pixels, which for tbirdhoops
// returns red + black + off-white — the real identity.
//
// DETERMINISM: same bytes always produce the same palette. No
// randomness, no k-means seeding — just quantise + histogram + rank +
// hue-cluster + role-assign. Runs in PHP GD (stdlib). Same-bytes-
// same-output pinned by test.
//
// ALPHA POLICY: transparent pixels do NOT contribute color and are
// NOT counted as white/light-grey. The tbirdhoops logo is 82.7%
// opaque with a large fully-transparent region; treating those
// pixels as white would swamp the real palette. Threshold = 128 (fully-
// transparent pixels dropped; semi-opaque pixels weighted at their
// alpha value / 255 to reduce their contribution).
//
// ROLE ASSIGNMENT:
//   text        := most-frequent cluster with luminance < 0.15
//   background  := most-frequent cluster with luminance > 0.80
//   primary     := most-frequent cluster excluding text + background
//   secondary   := next-most-frequent cluster (may be the same hue
//                  cluster as text/background — reusing text as
//                  secondary is honest for red-and-BLACK identities)
//   accent      := next cluster meeting BOTH:
//                    - hue-distinct from primary AND secondary
//                    - luminance in [0.15, 0.80] (accent must NOT be
//                      near-white/near-black; those are invisible-as-
//                      accent even when the club's brand is
//                      technically monochromatic. Better null accent
//                      than fake highlight.)
//
// Roles that can't be filled from measurement return null; callers
// decide fallback. Deliberately does NOT fabricate a third color for
// two-color clubs (red + black) — a fake accent is worse than a
// missing one.
final class LogoPaletteExtractor
{
    private const SAMPLE_WIDTH = 100;

    private const QUANT_STEP = 32;

    private const LUMINANCE_TEXT_MAX = 0.15;

    private const LUMINANCE_BG_MIN = 0.80;

    private const LUMINANCE_ACCENT_MIN = 0.15;

    private const LUMINANCE_ACCENT_MAX = 0.80;

    private const HUE_DISTANCE_MIN_DEGREES = 30.0;

    private const MIN_CLUSTER_SHARE = 0.005; // 0.5% minimum to consider a cluster real

    // Chromaticity = max(R,G,B) − min(R,G,B). Below this, the cluster
    // is treated as NEUTRAL (grey/black/white) and eligible for
    // text/background. Above this, it's a SATURATED color eligible
    // for primary/secondary/accent. Prevents dark reds (luminance
    // ≈ 0.1 but chroma > 100) from being misclassified as text.
    private const NEUTRAL_CHROMA_MAX = 40;

    /**
     * @return array{primary?: string, secondary?: string, accent?: string, background?: string, text?: string}|null
     *         null when GD fails to decode / no opaque pixels / all one color
     */
    public function extract(string $pngBytes): ?array
    {
        if ($pngBytes === '' || ! extension_loaded('gd')) {
            return null;
        }

        $im = @imagecreatefromstring($pngBytes);
        if ($im === false) {
            return null;
        }
        imagesavealpha($im, true);

        $srcW = imagesx($im);
        $srcH = imagesy($im);

        // Downsample to a bounded width for speed. Preserves aspect
        // ratio. Nearest-neighbor sampling would drop rare colors, so
        // we use bilinear (imagecopyresampled) which averages — this
        // means our histogram counts SUB-PIXEL averages rather than
        // exact pixels, which is what we want for order-of-magnitude
        // identification anyway.
        $dstW = min($srcW, self::SAMPLE_WIDTH);
        $dstH = max(1, (int) round($dstW * $srcH / $srcW));
        $dst = imagecreatetruecolor($dstW, $dstH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        if ($transparent === false) {
            imagedestroy($im);
            imagedestroy($dst);

            return null;
        }
        imagefill($dst, 0, 0, $transparent);
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($im);

        // Quantise + accumulate weighted counts.
        /** @var array<string, array{count: float, r: float, g: float, b: float}> $bins */
        $bins = [];
        $opaqueWeight = 0.0;
        for ($y = 0; $y < $dstH; $y++) {
            for ($x = 0; $x < $dstW; $x++) {
                $rgba = imagecolorat($dst, $x, $y);
                // GD packs alpha in the high 7 bits (0=opaque, 127=transparent).
                $alpha = ($rgba >> 24) & 0x7F;
                if ($alpha >= 96) {
                    continue; // near-fully-transparent — skip.
                }
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                // Weight: 1.0 at fully opaque (alpha=0), tapering to 0
                // at alpha≈127. Semi-opaque pixels contribute less.
                $weight = (127 - $alpha) / 127.0;
                $opaqueWeight += $weight;

                $qr = intdiv($r, self::QUANT_STEP) * self::QUANT_STEP;
                $qg = intdiv($g, self::QUANT_STEP) * self::QUANT_STEP;
                $qb = intdiv($b, self::QUANT_STEP) * self::QUANT_STEP;
                $key = $qr.'-'.$qg.'-'.$qb;
                if (! isset($bins[$key])) {
                    $bins[$key] = ['count' => 0.0, 'r' => 0.0, 'g' => 0.0, 'b' => 0.0];
                }
                $bins[$key]['count'] += $weight;
                // Track mean RGB within each quant-bin (for finer
                // reporting than the quantised bucket).
                $bins[$key]['r'] += $r * $weight;
                $bins[$key]['g'] += $g * $weight;
                $bins[$key]['b'] += $b * $weight;
            }
        }
        imagedestroy($dst);

        if ($opaqueWeight <= 0.0 || $bins === []) {
            return null;
        }

        // Convert bins to concrete color clusters + share, then merge
        // any two clusters within HUE_DISTANCE_MIN_DEGREES + close
        // luminance (weighted average).
        /** @var array<int, array{r: int, g: int, b: int, share: float, lum: float, hue: float, chroma: int}> $clusters */
        $clusters = [];
        foreach ($bins as $bin) {
            if ($bin['count'] / $opaqueWeight < self::MIN_CLUSTER_SHARE) {
                continue;
            }
            $r = (int) round($bin['r'] / $bin['count']);
            $g = (int) round($bin['g'] / $bin['count']);
            $b = (int) round($bin['b'] / $bin['count']);
            $clusters[] = [
                'r' => $r,
                'g' => $g,
                'b' => $b,
                'share' => $bin['count'] / $opaqueWeight,
                'lum' => $this->luminance($r, $g, $b),
                'hue' => $this->hue($r, $g, $b),
                'chroma' => max($r, $g, $b) - min($r, $g, $b),
            ];
        }
        if ($clusters === []) {
            return null;
        }

        // Merge hue-similar clusters. Rank by share desc, iterate,
        // absorb any subsequent cluster whose (hue, luminance) is
        // close into the running cluster. Weighted average.
        usort($clusters, static fn (array $a, array $b) => $b['share'] <=> $a['share']);
        /** @var array<int, array{r: int, g: int, b: int, share: float, lum: float, hue: float, chroma: int}> $merged */
        $merged = [];
        foreach ($clusters as $c) {
            $absorbed = false;
            foreach ($merged as $i => $m) {
                if ($this->clustersSimilar($m, $c)) {
                    // Weighted average.
                    $ws = $m['share'] + $c['share'];
                    $nr = (int) round(($m['r'] * $m['share'] + $c['r'] * $c['share']) / $ws);
                    $ng = (int) round(($m['g'] * $m['share'] + $c['g'] * $c['share']) / $ws);
                    $nb = (int) round(($m['b'] * $m['share'] + $c['b'] * $c['share']) / $ws);
                    $merged[$i] = [
                        'r' => $nr,
                        'g' => $ng,
                        'b' => $nb,
                        'share' => $ws,
                        'lum' => $this->luminance($nr, $ng, $nb),
                        'hue' => $this->hue($nr, $ng, $nb),
                        'chroma' => max($nr, $ng, $nb) - min($nr, $ng, $nb),
                    ];
                    $absorbed = true;
                    break;
                }
            }
            if (! $absorbed) {
                $merged[] = $c;
            }
        }
        usort($merged, static fn (array $a, array $b) => $b['share'] <=> $a['share']);

        return $this->assignRoles($merged);
    }

    /**
     * @param  array<int, array{r: int, g: int, b: int, share: float, lum: float, hue: float, chroma: int}>  $clusters
     * @return array{primary?: string, secondary?: string, accent?: string, background?: string, text?: string}|null
     */
    private function assignRoles(array $clusters): ?array
    {
        if ($clusters === []) {
            return null;
        }

        // TEXT/BG require BOTH low chroma (neutral — grey/black/white)
        // AND the target luminance band. A dark SATURATED color
        // (dark red, navy blue) is a brand color, NOT text — skipping
        // it here lets it fall into the primary/secondary pool below.
        $bgIndex = null;
        $textIndex = null;
        foreach ($clusters as $i => $c) {
            $isNeutral = $c['chroma'] <= self::NEUTRAL_CHROMA_MAX;
            if (! $isNeutral) {
                continue;
            }
            if ($textIndex === null && $c['lum'] <= self::LUMINANCE_TEXT_MAX) {
                $textIndex = $i;
            }
            if ($bgIndex === null && $c['lum'] >= self::LUMINANCE_BG_MIN) {
                $bgIndex = $i;
            }
        }

        // Primary/secondary pool = chromatic clusters (or neutral
        // clusters that were NOT slotted as bg/text). Excluding bg +
        // text index means brand black stays available for secondary
        // in a red-and-black club because black had chroma < 40 →
        // slotted as text — and reuse via the fallback below.
        $pool = [];
        foreach ($clusters as $i => $c) {
            if ($i === $bgIndex || $i === $textIndex) {
                continue;
            }
            // Chromatic clusters always eligible; neutral clusters
            // (mid-grey, not text/bg) can enter if they're a
            // meaningful share.
            $pool[$i] = $c;
        }

        // Primary: highest-share CHROMATIC cluster (chroma above
        // neutral threshold). Falls back to the highest-share pool
        // cluster of any kind if no chromatic exists.
        $primaryIndex = null;
        foreach ($pool as $i => $c) {
            if ($c['chroma'] > self::NEUTRAL_CHROMA_MAX) {
                $primaryIndex = $i;
                break;
            }
        }
        if ($primaryIndex === null) {
            $primaryIndex = array_key_first($pool);
        }
        $primary = $primaryIndex !== null ? $pool[$primaryIndex] : null;
        if ($primaryIndex !== null) {
            unset($pool[$primaryIndex]);
        }

        // Secondary: prefer another CHROMATIC pool cluster hue-
        // distinct from primary (a genuine second brand color). If
        // no chromatic secondary exists (mono-hue brand), reuse the
        // text cluster — red-and-BLACK clubs treat black as the
        // secondary brand color, and the text cluster IS that black.
        // Better honest reuse than a spurious mid-grey.
        $secondary = null;
        foreach ($pool as $c) {
            if ($c['chroma'] <= self::NEUTRAL_CHROMA_MAX) {
                continue;
            }
            if ($primary !== null && $this->hueDistance($c['hue'], $primary['hue']) < self::HUE_DISTANCE_MIN_DEGREES) {
                continue;
            }
            $secondary = $c;
            break;
        }
        if ($secondary === null && $textIndex !== null) {
            $secondary = $clusters[$textIndex];
        }

        // Accent: hue-distinct from primary AND secondary, mid-lum.
        // Falls through when the brand is genuinely two-color; leaves
        // accent unset rather than fabricating a third.
        $accent = null;
        foreach ($pool as $c) {
            $lum = $c['lum'];
            if ($lum < self::LUMINANCE_ACCENT_MIN || $lum > self::LUMINANCE_ACCENT_MAX) {
                continue;
            }
            if ($primary !== null && $this->hueDistance($c['hue'], $primary['hue']) < self::HUE_DISTANCE_MIN_DEGREES) {
                continue;
            }
            if ($secondary !== null && $this->hueDistance($c['hue'], $secondary['hue']) < self::HUE_DISTANCE_MIN_DEGREES) {
                continue;
            }
            $accent = $c;
            break;
        }

        $out = [];
        if ($primary !== null) {
            $out['primary'] = $this->hex($primary['r'], $primary['g'], $primary['b']);
        }
        if ($secondary !== null) {
            $out['secondary'] = $this->hex($secondary['r'], $secondary['g'], $secondary['b']);
        }
        if ($accent !== null) {
            $out['accent'] = $this->hex($accent['r'], $accent['g'], $accent['b']);
        }
        if ($bgIndex !== null) {
            $bg = $clusters[$bgIndex];
            $out['background'] = $this->hex($bg['r'], $bg['g'], $bg['b']);
        }
        if ($textIndex !== null) {
            $text = $clusters[$textIndex];
            $out['text'] = $this->hex($text['r'], $text['g'], $text['b']);
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param  array{lum: float, hue: float, chroma: int}  $a
     * @param  array{lum: float, hue: float, chroma: int}  $b
     */
    private function clustersSimilar(array $a, array $b): bool
    {
        // "Neutral" is CHROMA-based (max−min RGB), matching
        // assignRoles' definition. Using LUMINANCE here (the first
        // draft) was the bug that let dark red merge with pure
        // black — both had lum < 0.10, so "neutral" was true for
        // both, and the |lum diff| < 0.15 clause absorbed pure
        // black into the dark-red cluster. Consistent chroma-based
        // neutrality keeps brand-black separate from brand-red so
        // black surfaces as its own cluster for the text role.
        $aNeutral = $a['chroma'] <= self::NEUTRAL_CHROMA_MAX;
        $bNeutral = $b['chroma'] <= self::NEUTRAL_CHROMA_MAX;
        if ($aNeutral && $bNeutral) {
            return abs($a['lum'] - $b['lum']) < 0.15;
        }
        if ($aNeutral !== $bNeutral) {
            return false;
        }

        return $this->hueDistance($a['hue'], $b['hue']) < 20.0 && abs($a['lum'] - $b['lum']) < 0.25;
    }

    private function hueDistance(float $a, float $b): float
    {
        $d = abs($a - $b);

        return $d > 180.0 ? 360.0 - $d : $d;
    }

    // WCAG relative luminance (0..1).
    private function luminance(int $r, int $g, int $b): float
    {
        $lin = function (int $c): float {
            $s = $c / 255.0;

            return $s <= 0.03928 ? $s / 12.92 : pow(($s + 0.055) / 1.055, 2.4);
        };

        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    }

    // HSL hue in degrees (0..360). Undefined-hue (grey) collapses
    // to 0 — we handle greys via the near-neutral branch in
    // clustersSimilar, so a spurious 0 there doesn't hurt.
    private function hue(int $r, int $g, int $b): float
    {
        $rf = $r / 255.0;
        $gf = $g / 255.0;
        $bf = $b / 255.0;
        $max = max($rf, $gf, $bf);
        $min = min($rf, $gf, $bf);
        $delta = $max - $min;
        if ($delta < 0.001) {
            return 0.0;
        }
        if ($max === $rf) {
            $h = 60.0 * fmod(($gf - $bf) / $delta, 6.0);
        } elseif ($max === $gf) {
            $h = 60.0 * ((($bf - $rf) / $delta) + 2.0);
        } else {
            $h = 60.0 * ((($rf - $gf) / $delta) + 4.0);
        }
        if ($h < 0.0) {
            $h += 360.0;
        }

        return $h;
    }

    private function hex(int $r, int $g, int $b): string
    {
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }
}
