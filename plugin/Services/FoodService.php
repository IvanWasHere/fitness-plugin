<?php

namespace FitnessClub\Services;

use FitnessClub\Support\DomainException;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The food database behind the Search Food modal (W2.1).
 *
 * ## Why the search is not one query
 *
 * `fc_foods` carries `FULLTEXT KEY ft_name (name, brand)` because the modal is a
 * typeahead and `LIKE '%chick%'` cannot use an index — it degrades the moment
 * the table has more than a few thousand rows.
 *
 * But FULLTEXT alone does not answer a typeahead, for two reasons that both bite
 * silently:
 *
 *   - **Natural-language mode has no prefix matching.** Somebody typing "chick"
 *     wants "Chicken Breast"; natural-language mode matches whole words and
 *     returns nothing. Boolean mode with a trailing `*` does the prefix match,
 *     so that is what this uses.
 *   - **InnoDB ignores tokens shorter than `innodb_ft_min_token_size`**, three
 *     characters by default. The contract says the typeahead fires at two, so a
 *     two-character query would reliably return an empty list on a stock MySQL —
 *     which reads as "we have no foods" rather than "your server has a setting".
 *
 * So: FULLTEXT for tokens long enough for the index, `LIKE 'x%'` for the short
 * ones. The LIKE is anchored at the start, which *can* use the ordinary index on
 * a prefix, rather than the unanchored `%x%` that cannot use anything.
 */
final class FoodService
{
    /** Below this, FULLTEXT is unreliable across MySQL configurations. */
    private const MIN_FULLTEXT_TOKEN = 3;

    private const MAX_RESULTS = 50;

    /**
     * Search the food database.
     *
     * @param array<string,mixed> $filters q, category, per_page
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters = []): array
    {
        global $wpdb;

        $query    = trim((string) ($filters['q'] ?? ''));
        $category = trim((string) ($filters['category'] ?? ''));
        $limit    = max(1, min(self::MAX_RESULTS, (int) ($filters['per_page'] ?? 20)));

        $where  = ['1=1'];
        $values = [];

        if ('' !== $category) {
            $where[]  = 'category = %s';
            $values[] = $category;
        }

        // Relevance first when there is a search term, then verified system
        // foods ahead of somebody's one-off custom entry, then alphabetically.
        $order = 'is_verified DESC, name ASC';

        if ('' !== $query) {
            if ($this->longEnoughForFulltext($query)) {
                $where[]  = 'MATCH(name, brand) AGAINST (%s IN BOOLEAN MODE)';
                $values[] = $this->booleanQuery($query);
                $order    = 'MATCH(name, brand) AGAINST (%s IN BOOLEAN MODE) DESC, is_verified DESC, name ASC';
            } else {
                $where[]  = '(name LIKE %s OR brand LIKE %s)';
                $like     = $wpdb->esc_like($query) . '%';
                $values[] = $like;
                $values[] = $like;
            }
        }

        $clause = implode(' AND ', $where);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $clause/$order are placeholder expressions built above; values bind below.
        $sql = "SELECT id, name, brand, category, serving_size, serving_grams, calories,
                       protein_g, carbs_g, fat_g, fiber_g, sugar_g, sodium_mg, source, is_verified
                  FROM {$wpdb->prefix}fc_foods
                 WHERE {$clause}
                 ORDER BY {$order}
                 LIMIT %d";

        // The relevance expression in ORDER BY needs the search term a second
        // time, and it is bound after the WHERE values because that is where the
        // placeholder sits in the statement.
        if ('' !== $query && $this->longEnoughForFulltext($query)) {
            $values[] = $this->booleanQuery($query);
        }

        $values[] = $limit;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$values), ARRAY_A) ?: [];

        return array_map([$this, 'present'], $rows);
    }

    /**
     * A single food by barcode.
     *
     * @return array<string,mixed>|null
     */
    public function findByBarcode(string $barcode): ?array
    {
        global $wpdb;

        $barcode = preg_replace('/[^0-9A-Za-z]/', '', $barcode) ?? '';

        if ('' === $barcode) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, brand, category, serving_size, serving_grams, calories,
                    protein_g, carbs_g, fat_g, fiber_g, sugar_g, sodium_mg, source, is_verified
               FROM {$wpdb->prefix}fc_foods WHERE barcode = %s LIMIT 1",
            $barcode
        ), ARRAY_A);

        return is_array($row) ? $this->present($row) : null;
    }

    /**
     * Create a custom food.
     *
     * Always `source = 'user'` and `is_verified = 0`, whoever is asking: a
     * member adding "Mum's lasagne" must not be able to plant a row that then
     * outranks the verified database in everyone else's search.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function create(array $payload, int $accountId): array
    {
        global $wpdb;

        $name = trim((string) ($payload['name'] ?? ''));

        if ('' === $name) {
            throw new DomainException(
                'fc_food_name_required',
                __('A food needs a name.', 'fitnessclub'),
                400
            );
        }

        $categories = (array) FitnessClub()->config('fitnessclub.enums.food_category', []);
        $category   = (string) ($payload['category'] ?? 'other');
        $now        = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_foods', [
            'name'          => $name,
            'brand'         => $this->trimOrNull($payload['brand'] ?? null),
            'category'      => in_array($category, $categories, true) ? $category : 'other',
            'serving_size'  => $this->trimOrNull($payload['serving_size'] ?? null) ?? '1 serving',
            'serving_grams' => isset($payload['serving_grams']) ? (float) $payload['serving_grams'] : null,
            'calories'      => max(0, (int) ($payload['calories'] ?? 0)),
            'protein_g'     => round(max(0, (float) ($payload['protein_g'] ?? 0)), 2),
            'carbs_g'       => round(max(0, (float) ($payload['carbs_g'] ?? 0)), 2),
            'fat_g'         => round(max(0, (float) ($payload['fat_g'] ?? 0)), 2),
            'fiber_g'       => isset($payload['fiber_g']) ? round((float) $payload['fiber_g'], 2) : null,
            'sugar_g'       => isset($payload['sugar_g']) ? round((float) $payload['sugar_g'], 2) : null,
            'sodium_mg'     => isset($payload['sodium_mg']) ? round((float) $payload['sodium_mg'], 2) : null,
            'source'        => 'user',
            'created_by_account_id' => $accountId > 0 ? $accountId : null,
            'is_verified'   => 0,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $id = (int) $wpdb->insert_id;

        return $this->present((array) $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, brand, category, serving_size, serving_grams, calories,
                    protein_g, carbs_g, fat_g, fiber_g, sugar_g, sodium_mg, source, is_verified
               FROM {$wpdb->prefix}fc_foods WHERE id = %d",
            $id
        ), ARRAY_A));
    }

    /**
     * Is any word long enough for the FULLTEXT index to see it?
     */
    private function longEnoughForFulltext(string $query): bool
    {
        return [] !== $this->tokenise($query);
    }

    /**
     * Turn "chicken bre" into `+chicken* +bre*` — every word required, each
     * matched as a prefix, which is what a typeahead means.
     */
    private function booleanQuery(string $query): string
    {
        return implode(' ', array_map(
            static fn(string $word): string => '+' . $word . '*',
            $this->tokenise($query)
        ));
    }

    /**
     * Split a query into words the index can match.
     *
     * **Punctuation separates, it does not vanish.** Splitting on whitespace
     * alone and then stripping the operators turns "low-fat" into the single
     * token "lowfat", which matches nothing — the row says "Low Fat". And
     * leaving the hyphen in is worse: in boolean mode a bare `-` means NOT, so
     * "low-fat" would ask for rows containing "low" but *excluding* "fat",
     * quietly hiding the exact rows the member was looking for.
     *
     * @return string[] Words at least MIN_FULLTEXT_TOKEN characters long.
     */
    private function tokenise(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $words,
            static fn(string $word): bool => mb_strlen($word) >= self::MIN_FULLTEXT_TOKEN
        ));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'name'          => $row['name'],
            'brand'         => $row['brand'],
            'category'      => $row['category'],
            'serving_size'  => $row['serving_size'],
            'serving_grams' => null === $row['serving_grams'] ? null : (float) $row['serving_grams'],
            'calories'      => (int) $row['calories'],
            'protein_g'     => round((float) $row['protein_g'], 1),
            'carbs_g'       => round((float) $row['carbs_g'], 1),
            'fat_g'         => round((float) $row['fat_g'], 1),
            'fiber_g'       => null === $row['fiber_g'] ? null : round((float) $row['fiber_g'], 1),
            'sugar_g'       => null === $row['sugar_g'] ? null : round((float) $row['sugar_g'], 1),
            'sodium_mg'     => null === $row['sodium_mg'] ? null : round((float) $row['sodium_mg'], 1),
            'source'        => $row['source'],
            'is_verified'   => (bool) $row['is_verified'],
        ];
    }

    private function trimOrNull($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
