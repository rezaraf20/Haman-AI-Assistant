<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * "Did the shop actually have what the bot said it didn't?"
 *
 * The comparison feature's matching (python-ai-service comparison_resolver:
 * normalize_digits + difflib.SequenceMatcher), ported faithfully so "فلاسک
 * ۸۰۰" and "فلاسک 800 میل" compare the same here as there — ratio() is
 * Ratcliff/Obershelp over characters, exactly difflib's ratio for strings
 * under 200 characters (difflib's autojunk only applies above that), and
 * tests pin it to values computed by Python.
 *
 * Matching a whole customer question against every product name is looser
 * than the comparison feature's setting (it scores names a product search
 * already returned), so the rule is stricter, measured on khonehrangi.ir's
 * 17 "we don't have it" replies against its 173 products: at least TWO
 * distinctive words shared (one is not enough: "ماگ آبی" vs "ماگ شبح" scores
 * 0.71 on the word "ماگ" alone), and either the ratio of the best window of
 * the question ≥ 0.75 or every distinctive word of the question appears in
 * the name ("تراول ماگ" ⊂ "تراول ماگ ۶۵۰ میل"). Missing a case is preferred
 * to accusing the bot of one it didn't make.
 */
class CatalogMatch
{
    public const MIN_RATIO = 0.75;
    public const MIN_SHARED_WORDS = 2;

    /** Words that say nothing about which product: verbs of asking/wanting, fillers. */
    private const STOPWORDS = ['من', 'و', 'هم', 'را', 'رو', 'با', 'از', 'به', 'در', 'که', 'این', 'آن', 'یا', 'چند', 'چه', 'کی', 'آیا',
        'دارید', 'دارین', 'داری', 'داره', 'دارد', 'داریم', 'میخوام', 'میخواستم', 'می', 'خوام', 'دنبال', 'هستم', 'هست', 'موجود', 'موجوده', 'سلام', 'لطفا', 'لطفاً',
        'مقایسه', 'کن', 'کنید', 'بذار', 'بزار', 'تو', 'سبد', 'خرید', 'قیمت', 'چنده', 'چقدر', 'ممنون', 'do', 'you', 'have', 'the', 'a', 'is', 'i', 'want'];

    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    private const ARABIC_INDIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    /** comparison_resolver.normalize_digits: Persian/Arabic-Indic digits to ASCII. */
    public static function normalizeDigits(string $text): string
    {
        return str_replace([...self::PERSIAN_DIGITS, ...self::ARABIC_INDIC_DIGITS], [...range(0, 9), ...range(0, 9)], $text);
    }

    /** Lower-case, unified Persian letters, ZWNJ → space, punctuation dropped. */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(self::normalizeDigits($text));
        $text = str_replace(['ي', 'ك', "\u{200C}"], ['ی', 'ک', ' '], $text);
        $text = preg_replace('/[؟?!.,،:;()«»"\'\-_\/]+/u', ' ', $text) ?? $text;
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** @return string[] */
    public static function words(string $text): array
    {
        $words = explode(' ', self::normalize($text));
        return array_values(array_filter($words, fn ($w) => $w !== '' && mb_strlen($w) >= 2 && !in_array($w, self::STOPWORDS, true)));
    }

    /** difflib.SequenceMatcher(a, b).ratio(), on characters. */
    public static function ratio(string $a, string $b): float
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $total = count($x) + count($y);
        return $total === 0 ? 1.0 : 2.0 * self::matching($x, 0, count($x), $y, 0, count($y)) / $total;
    }

    /** Ratcliff/Obershelp: the longest common block, then recursively left and right of it (difflib's get_matching_blocks). */
    private static function matching(array $a, int $alo, int $ahi, array $b, int $blo, int $bhi): int
    {
        [$i, $j, $k] = self::longest($a, $alo, $ahi, $b, $blo, $bhi);
        if ($k === 0) return 0;
        return $k
            + ($alo < $i && $blo < $j ? self::matching($a, $alo, $i, $b, $blo, $j) : 0)
            + ($i + $k < $ahi && $j + $k < $bhi ? self::matching($a, $i + $k, $ahi, $b, $j + $k, $bhi) : 0);
    }

    /** difflib.find_longest_match without junk: earliest longest block, ties to the lowest i then j. */
    private static function longest(array $a, int $alo, int $ahi, array $b, int $blo, int $bhi): array
    {
        $b2j = [];
        for ($j = $blo; $j < $bhi; $j++) $b2j[$b[$j]][] = $j;
        $besti = $alo; $bestj = $blo; $bestsize = 0;
        $j2len = [];
        for ($i = $alo; $i < $ahi; $i++) {
            $new = [];
            foreach ($b2j[$a[$i]] ?? [] as $j) {
                $k = ($j2len[$j - 1] ?? 0) + 1;
                $new[$j] = $k;
                if ($k > $bestsize) { $besti = $i - $k + 1; $bestj = $j - $k + 1; $bestsize = $k; }
            }
            $j2len = $new;
        }
        return [$besti, $bestj, $bestsize];
    }

    /**
     * The best product a question was asking about, or null.
     * @param array<array{name:string, stock_status:?string}> $products
     * @return array{name:string, stock_status:?string, ratio:float, shared:int}|null — in stock preferred
     */
    public static function find(string $question, array $products): ?array
    {
        $q = self::words($question);
        if (count($q) < 1) return null;

        $best = null;
        foreach ($products as $p) {
            $n = self::words((string) ($p['name'] ?? ''));
            if (!$n) continue;
            $shared = count(array_intersect(array_unique($q), array_unique($n)));
            if ($shared < self::MIN_SHARED_WORDS) continue;

            $name = implode(' ', $n);
            $ratio = 0.0;
            $len = count($n);
            for ($size = max(1, $len - 1); $size <= $len + 1; $size++) {
                for ($i = 0; $i <= max(0, count($q) - $size); $i++) {
                    $window = implode(' ', array_slice($q, $i, $size));
                    if ($window !== '') $ratio = max($ratio, self::ratio($window, $name));
                }
            }
            $contained = !array_diff($q, $n);
            if ($ratio < self::MIN_RATIO && !$contained) continue;

            $candidate = ['name' => (string) $p['name'], 'stock_status' => $p['stock_status'] ?? null, 'ratio' => round($ratio, 3), 'shared' => $shared];
            $rank = fn ($c) => [($c['stock_status'] ?? 'instock') !== 'outofstock' ? 1 : 0, $c['ratio'], $c['shared']];
            if ($best === null || $rank($candidate) > $rank($best)) $best = $candidate;
        }
        return $best;
    }

    /** The catalogue of one chatbot (its tenant schema), names and stock only. */
    public static function catalogue(string $chatbotId): array
    {
        $schema = DB::table('public.chatbot_index')->where('chatbot_id', $chatbotId)->value('schema_name');
        if (!$schema) return [];
        return DB::table("{$schema}.products")->where('chatbot_id', $chatbotId)
            ->get(['name', 'stock_status'])->map(fn ($r) => ['name' => $r->name, 'stock_status' => $r->stock_status])->all();
    }
}
