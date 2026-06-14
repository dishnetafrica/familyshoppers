<?php

namespace App\Services\Bot;

/**
 * DiscoveryContextBuilder — turns a free-text discovery message into a structured shopping
 * context the recommender can reason over, instead of a blob of words.
 *
 *   "Need rice / not basmati / daily use / family of 5 / not expensive"
 *      -> [ 'product' => 'rice', 'exclude' => ['basmati'], 'usage' => 'daily',
 *           'family_size' => 5, 'budget' => 'low' ]
 *
 * How each field is USED (kept honest — no field claims more than the catalogue supports):
 *   product      -> the search subject (head noun, not a colliding token).
 *   exclude      -> products carrying these tokens are dropped ("not basmati").
 *   budget       -> biases the pick toward the cheaper ('low') or pricier ('high') end.
 *   family_size  -> a soft nudge toward a larger pack when sizes are present.
 *   usage        -> shapes the WORDING ("for daily use ..."); it only changes the PICK when
 *                   products are tagged with that usage in keywords, otherwise it is echo only.
 *
 * Pure & static. Reuses SalesAssistantBrain's token/exclusion helpers so extraction stays
 * consistent with the matcher.
 */
class DiscoveryContextBuilder
{
    public static function build(string $segment, array $catalogue): array
    {
        return [
            'product'     => SalesAssistantBrain::subjectTerm($segment, $catalogue),
            'exclude'     => array_keys(SalesAssistantBrain::excludedTerms($segment)),
            'usage'       => self::usage($segment),
            'family_size' => self::familySize($segment),
            'budget'      => self::budget($segment),
            'size'        => SalesAssistantBrain::parseSize($segment),
        ];
    }

    /** 'daily' | 'special' | 'cooking' | null */
    public static function usage(string $segment): ?string
    {
        $s = mb_strtolower($segment);
        if (preg_match('/\bdaily( use| meals?)?\b|\beveryday\b|\bregular use\b|\bhome use\b/', $s)) return 'daily';
        if (preg_match('/\bbiryani\b|\bpulao\b|\bspecial( occasion)?\b|\bparty\b|\bguests?\b|\bfeast\b/', $s)) return 'special';
        if (preg_match('/\bcooking\b|\bfrying\b|\bbaking\b/', $s)) return 'cooking';
        return null;
    }

    /** Number of people in the household, or null. */
    public static function familySize(string $segment): ?int
    {
        $s = mb_strtolower($segment);
        if (preg_match('/\bfamily of (\d{1,2})\b/', $s, $m)) return (int) $m[1];
        if (preg_match('/\b(\d{1,2})\s+(people|persons?|members?|of us|heads?)\b/', $s, $m)) return (int) $m[1];
        if (preg_match('/\bfor (\d{1,2})\b/', $s, $m)) return (int) $m[1];
        return null;
    }

    /** 'low' | 'high' | null */
    public static function budget(string $segment): ?string
    {
        $s = mb_strtolower($segment);
        if (preg_match('/\b(not (too )?(expensive|costly|pricey))\b|\bcheap(est|er)?\b|\baffordable\b|\bbudget\b|\binexpensive\b|\beconomical\b|\blow ?price\b|\bvalue for money\b/', $s)) {
            return 'low';
        }
        if (preg_match('/\b(premium|best quality|high quality|top quality|finest|expensive is fine|price no|money no)\b/', $s)) {
            return 'high';
        }
        return null;
    }

    /**
     * A natural-language prefix echoing the customer's stated context, e.g.
     * "For a family of 5 looking for affordable daily-use rice, ". Empty when no context.
     */
    public static function phrase(array $ctx): string
    {
        $product = trim((string) ($ctx['product'] ?? ''));
        $bits = [];
        if (! empty($ctx['family_size'])) $bits[] = 'a family of ' . (int) $ctx['family_size'];
        $qual = [];
        if (($ctx['budget'] ?? null) === 'low')  $qual[] = 'affordable';
        if (($ctx['budget'] ?? null) === 'high') $qual[] = 'premium';
        if (($ctx['usage'] ?? null) === 'daily')   $qual[] = 'daily-use';
        if (($ctx['usage'] ?? null) === 'special') $qual[] = 'special-occasion';
        if (($ctx['usage'] ?? null) === 'cooking') $qual[] = 'cooking';

        $tail = trim(implode(' ', $qual) . ($product !== '' ? ' ' . $product : ''));
        if ($bits && $tail !== '') return 'For ' . implode(' ', $bits) . ' looking for ' . $tail . ', ';
        if ($bits)                 return 'For ' . implode(' ', $bits) . ', ';
        if ($qual && $product!=='') return 'For ' . implode(' ', $qual) . ' ' . $product . ', ';
        return '';
    }
}
