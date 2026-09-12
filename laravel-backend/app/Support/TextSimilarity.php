<?php
namespace App\Support;

/**
 * Groups near-duplicate customer questions so a report shows "23 people
 * asked about shipping cost" instead of 23 near-identical rows.
 *
 * Deliberately NOT embeddings: this runs inside a portal page render for
 * a merchant looking at their own data, and paying for an embedding call
 * per question (plus the latency) to cluster a few hundred short strings
 * would be absurd. Token-overlap clustering is crude but honest, and it
 * degrades gracefully — a question that matches nothing simply stays in
 * its own group of one.
 *
 * Persian needs real normalisation before any of this works: the same
 * word routinely arrives with Arabic ي/ك instead of Persian ی/ک, with or
 * without ZWNJ, and with Arabic-Indic digits. Without folding those, two
 * identical questions look completely different to a byte comparison.
 */
class TextSimilarity
{
    /** Words too common to carry meaning — they would make everything
     *  look similar to everything else. Persian first, then English. */
    private const STOPWORDS = [
        'از', 'به', 'با', 'در', 'که', 'را', 'و', 'یا', 'این', 'آن', 'برای', 'تا', 'هم', 'می',
        'است', 'هست', 'بود', 'شد', 'شده', 'کرد', 'کنم', 'کنید', 'کند', 'دارد', 'دارید', 'دارم',
        'چه', 'چی', 'چند', 'چطور', 'چگونه', 'کی', 'کجا', 'ایا', 'آیا', 'من', 'شما', 'ما', 'او',
        'های', 'ها', 'یک', 'دو', 'سلام', 'لطفا', 'لطفاً', 'ممنون', 'میشه', 'بشه', 'کنه', 'داره',
        'the', 'a', 'an', 'is', 'are', 'was', 'to', 'of', 'and', 'or', 'in', 'on', 'for', 'with',
        'do', 'does', 'did', 'i', 'you', 'we', 'it', 'my', 'your', 'can', 'could', 'would',
        'what', 'how', 'when', 'where', 'why', 'please', 'hi', 'hello', 'thanks',
    ];

    /** Below this Jaccard overlap two questions are treated as different
     *  topics. Chosen to be forgiving of one differing word in a short
     *  question, without collapsing genuinely distinct ones. */
    private const SIMILARITY_THRESHOLD = 0.5;

    /**
     * Folds the spelling variants that make identical Persian text compare
     * as different: Arabic letters to Persian, Arabic-Indic digits to
     * ASCII, ZWNJ and diacritics away, punctuation to spaces.
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));

        $map = [
            'ي' => 'ی', 'ك' => 'ک', 'ؤ' => 'و', 'إ' => 'ا', 'أ' => 'ا', 'آ' => 'ا', 'ة' => 'ه',
            "\u{200c}" => ' ', "\u{200f}" => '', "\u{200e}" => '',
        ];
        // Arabic-Indic and Persian digits -> ASCII.
        foreach (['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'] as $i => $d) $map[$d] = (string) $i;
        foreach (['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'] as $i => $d) $map[$d] = (string) $i;
        $text = strtr($text, $map);

        // Harakat/tatweel carry no meaning for matching.
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0640}]/u', '', $text) ?? $text;
        // Anything that isn't a letter or digit becomes a separator.
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** Meaningful tokens only — normalised, stopwords and 1-char noise removed. */
    public static function tokens(string $text): array
    {
        $words = explode(' ', self::normalize($text));
        $out = [];
        foreach ($words as $w) {
            if ($w === '' || mb_strlen($w) < 2) continue;
            if (in_array($w, self::STOPWORDS, true)) continue;
            $out[$w] = true;
        }
        return array_keys($out);
    }

    /** Overlap of two token sets, 0..1. Two empty sets are not "identical". */
    public static function jaccard(array $a, array $b): float
    {
        if (!$a || !$b) return 0.0;
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));
        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * Greedy single-pass clustering: each item joins the first group whose
     * representative it resembles, otherwise starts its own. Greedy (not
     * optimal) on purpose — it is O(n·groups), stable, and easy to explain
     * to a merchant looking at the output, which matters more here than
     * squeezing out a marginally better grouping.
     *
     * @param array $items list of ['text' => string, 'count' => int]
     * @return array list of ['label' => string, 'count' => int, 'variants' => string[]]
     *               sorted by count desc.
     */
    public static function group(array $items, int $limit = 20): array
    {
        $groups = [];

        foreach ($items as $item) {
            $text = trim((string) ($item['text'] ?? ''));
            if ($text === '') continue;
            $count = (int) ($item['count'] ?? 1);
            $tokens = self::tokens($text);

            $matched = false;
            foreach ($groups as &$g) {
                if (self::jaccard($tokens, $g['tokens']) >= self::SIMILARITY_THRESHOLD) {
                    $g['count'] += $count;
                    // Keep the shortest phrasing as the label: it is
                    // usually the clearest statement of the same question.
                    if (mb_strlen($text) < mb_strlen($g['label'])) $g['label'] = $text;
                    if (count($g['variants']) < 5) $g['variants'][] = $text;
                    $matched = true;
                    break;
                }
            }
            unset($g);

            if (!$matched) {
                $groups[] = ['label' => $text, 'count' => $count, 'tokens' => $tokens, 'variants' => [$text]];
            }
        }

        usort($groups, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_map(fn ($g) => [
            'label'    => $g['label'],
            'count'    => $g['count'],
            'variants' => array_values(array_unique($g['variants'])),
        ], array_slice($groups, 0, $limit));
    }
}
