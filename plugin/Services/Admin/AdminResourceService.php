<?php

namespace FitnessClub\Services\Admin;

use FitnessClub\Services\ActivityService;
use FitnessClub\Support\DomainException;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * One paginated CRUD engine for every admin resource (plans/05-admin-app.md, W2.5).
 *
 * ## Server-side is the whole point
 *
 * The prototype's `CrudTable` did `db[table].toCollection().sortBy()` — **loading
 * every row** — then searched, filtered and paginated in the browser. That is
 * fine for six seeded users and fatal at ten thousand; 05 calls it "the single
 * most important change in the admin port". Search, filter, sort and paging all
 * happen in SQL here, and the client sends parameters rather than receiving the
 * table.
 *
 * ## Identifiers come from the registry, values come from the request
 *
 * Column names cannot be bound as placeholders, so they are interpolated — which
 * is safe only because every one of them is a literal in
 * {@see ResourceRegistry}. The request names a *sort key*; the registry decides
 * what SQL that means, and an unknown key falls back to the default rather than
 * reaching the query. Values are always bound.
 */
final class AdminResourceService
{
    private ActivityService $activity;

    public function __construct()
    {
        $this->activity = new ActivityService();
    }

    /**
     * A page of rows.
     *
     * @param array<string,mixed> $query page, per_page, q, sort, order, and filters
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function index(string $resource, array $query): array
    {
        global $wpdb;

        $config = $this->config($resource);
        $alias  = $config['alias'];

        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;

        [$where, $params] = $this->where($config, $query);
        $joins = $this->joins($config);

        $countSql = sprintf(
            'SELECT COUNT(DISTINCT %s.id) FROM %s%s %s %s WHERE %s',
            $alias,
            $wpdb->prefix,
            $config['table'],
            $alias,
            $joins,
            $where
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers from the registry, values bound.
        $total = (int) $wpdb->get_var($params === [] ? $countSql : $wpdb->prepare($countSql, ...$params));

        [$sortExpr, $direction] = $this->sort($config, $query);

        $selectParams = $config['select_params'] ?? [];

        $sql = sprintf(
            'SELECT %s FROM %s%s %s %s WHERE %s ORDER BY %s %s LIMIT %%d OFFSET %%d',
            $this->select($config),
            $wpdb->prefix,
            $config['table'],
            $alias,
            $joins,
            $where,
            $sortExpr,
            $direction
        );

        $all = array_merge($selectParams, $params, [$perPage, $offset]);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers from the registry, values bound.
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$all), ARRAY_A) ?: [];

        return [
            'items'    => array_map(fn(array $row): array => $this->present($config, $row), $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * One row, with its nested children when it has any.
     *
     * @return array<string,mixed>
     */
    public function show(string $resource, int $id): array
    {
        $config = $this->config($resource);
        $row    = $this->present($config, $this->row($config, $id));

        if (($config['nested'] ?? null) === 'exercises') {
            $row['exercises'] = $this->exercises($id);
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function create(string $resource, array $payload, int $actorAccountId): array
    {
        global $wpdb;

        $config = $this->config($resource);

        if (!($config['creatable'] ?? true)) {
            throw new DomainException(
                'fc_not_creatable',
                /* translators: %s: resource name */
                sprintf(__('%s cannot be created here.', 'fitnessclub'), $resource),
                400
            );
        }

        $fields = $this->clean($config, $payload, true);
        $now    = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . $config['table'], $fields + [
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $wpdb->insert_id;

        if (($config['nested'] ?? null) === 'exercises' && isset($payload['exercises'])) {
            $this->syncExercises($id, (array) $payload['exercises']);
        }

        $this->auditIfNeeded($config, $resource, $id, [], $fields, $actorAccountId, 'created');

        return $this->show($resource, $id);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function update(string $resource, int $id, array $payload, int $actorAccountId): array
    {
        global $wpdb;

        $config = $this->config($resource);
        $before = $this->row($config, $id);

        // Reordering exercises submits *only* the children — no parent column
        // changes at all — so an empty field set is a real edit here, not an
        // empty request. Refusing it would make drag-reorder fail with
        // "nothing to change".
        $hasNested = ($config['nested'] ?? null) === 'exercises' && isset($payload['exercises']);
        $fields    = $this->clean($config, $payload, false, $hasNested);

        if ([] !== $fields) {
            // Q10 provenance: a staff edit must be distinguishable from a
            // self-reported reading, or the member's chart moves with no visible
            // cause. The columns only exist on the audited resources.
            if ($config['audit'] ?? false) {
                $fields['source']                    = 'admin';
                $fields['last_edited_by_account_id'] = $actorAccountId;
            }

            $fields['updated_at'] = gmdate('Y-m-d H:i:s');

            $wpdb->update($wpdb->prefix . $config['table'], $fields, ['id' => $id]);
        }

        if (($config['nested'] ?? null) === 'exercises' && isset($payload['exercises'])) {
            $this->syncExercises($id, (array) $payload['exercises']);
        }

        // Editing a health row changes derived numbers the member sees.
        if ('health-entries' === $resource) {
            $this->refreshHealthDerived($id, (int) $before['user_id']);
        }
        if ('meals' === $resource) {
            $this->refreshMealDerived($before, $fields);
        }

        $this->auditIfNeeded($config, $resource, $id, $before, $fields, $actorAccountId, 'updated');

        return $this->show($resource, $id);
    }

    public function delete(string $resource, int $id, int $actorAccountId): void
    {
        global $wpdb;

        $config = $this->config($resource);
        $before = $this->row($config, $id);

        $this->assertDeletable($resource, $id);

        $wpdb->delete($wpdb->prefix . $config['table'], ['id' => $id]);

        if ('health-entries' === $resource || 'meals' === $resource) {
            $this->announce((int) $before['user_id']);
        }

        $this->auditIfNeeded($config, $resource, $id, $before, [], $actorAccountId, 'deleted');
    }

    // ------------------------------------------------------------- internals

    /**
     * Refuse a delete that would orphan live data, with a reason.
     *
     * The prototype hard-deleted anything: removing a trainer silently detached
     * their clients' assignments. 05 asks for a 409 that explains instead.
     */
    private function assertDeletable(string $resource, int $id): void
    {
        global $wpdb;

        if ('trainers' === $resource) {
            $clients = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fc_user_trainers
                  WHERE trainer_id = %d AND status = 'active'",
                $id
            ));

            if ($clients > 0) {
                throw new DomainException(
                    'fc_trainer_has_clients',
                    sprintf(
                        /* translators: %d: number of clients */
                        _n(
                            'This trainer still has %d active client. Reassign them first.',
                            'This trainer still has %d active clients. Reassign them first.',
                            $clients,
                            'fitnessclub'
                        ),
                        $clients
                    ),
                    409
                );
            }
        }

        if ('workouts' === $resource) {
            $sessions = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fc_workout_sessions WHERE workout_id = %d",
                $id
            ));

            if ($sessions > 0) {
                throw new DomainException(
                    'fc_workout_has_sessions',
                    __(
                        'Members have logged sessions against this workout. Deactivate it instead of deleting it, so their history survives.',
                        'fitnessclub'
                    ),
                    409
                );
            }
        }

        if ('foods' === $resource) {
            $logged = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fc_nutrition_log_items WHERE food_id = %d",
                $id
            ));

            if ($logged > 0) {
                // The item rows *copy* their nutrients (W2.1), so the diary
                // survives — but the food is still referenced, and deleting it
                // would break the "log this again" path.
                throw new DomainException(
                    'fc_food_in_use',
                    __('This food appears in members\' diaries. Unverify it instead of deleting it.', 'fitnessclub'),
                    409
                );
            }
        }
    }

    /**
     * Replace a workout's exercises, **preserving ids**.
     *
     * The prototype's `WorkoutForm` did `delete ex.id` and re-added every row on
     * every save (05, "fixes carried from the prototype"). `fc_exercise_logs`
     * references `exercise_id`, so a delete-all-and-reinsert silently detaches
     * every historical log from the movement it recorded — the member's
     * "Bench Press over time" chart empties out because the ids it joins on no
     * longer exist.
     *
     * So: rows with an id are updated, rows without are inserted, and only rows
     * the payload actually dropped are deleted.
     *
     * @param array<int,mixed> $submitted
     */
    private function syncExercises(int $workoutId, array $submitted): void
    {
        global $wpdb;

        $table    = $wpdb->prefix . 'fc_exercises';
        $existing = array_map(
            'intval',
            $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fc_exercises WHERE workout_id = %d",
                $workoutId
            )) ?: []
        );

        $now  = gmdate('Y-m-d H:i:s');
        $kept = [];

        foreach (array_values($submitted) as $index => $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $fields = [
                'workout_id'           => $workoutId,
                'exercise_name'        => $this->text($raw['exercise_name'] ?? ''),
                'exercise_type'        => $this->text($raw['exercise_type'] ?? 'strength'),
                'muscle_groups'        => $this->jsonList($raw['muscle_groups'] ?? null),
                'instructions'         => $this->textarea($raw['instructions'] ?? null),
                'notes'                => $this->textarea($raw['notes'] ?? null),
                'video_url'            => $this->url($raw['video_url'] ?? null),
                'thumbnail_url'        => $this->url($raw['thumbnail_url'] ?? null),
                'default_sets'         => max(0, (int) ($raw['default_sets'] ?? 3)),
                'default_reps'         => max(0, (int) ($raw['default_reps'] ?? 10)),
                'default_weight_kg'    => max(0, (float) ($raw['default_weight_kg'] ?? 0)),
                'default_rest_seconds' => max(0, (int) ($raw['default_rest_seconds'] ?? 60)),
                'metric'               => $this->text($raw['metric'] ?? 'reps'),
                // Position comes from the array order the client submits, which
                // is what makes drag-reorder work without a separate endpoint.
                'order_index'          => $index,
                'updated_at'           => $now,
            ];

            if ('' === $fields['exercise_name']) {
                continue;
            }

            $id = isset($raw['id']) ? (int) $raw['id'] : 0;

            if ($id > 0 && in_array($id, $existing, true)) {
                $wpdb->update($table, $fields, ['id' => $id, 'workout_id' => $workoutId]);
                $kept[] = $id;

                continue;
            }

            $wpdb->insert($table, $fields + ['created_at' => $now]);
            $kept[] = (int) $wpdb->insert_id;
        }

        foreach (array_diff($existing, $kept) as $goneId) {
            $wpdb->delete($table, ['id' => $goneId, 'workout_id' => $workoutId]);
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function exercises(int $workoutId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_exercises WHERE workout_id = %d
              ORDER BY order_index ASC, id ASC",
            $workoutId
        ), ARRAY_A) ?: [];

        return array_map(static function (array $row): array {
            $muscles = json_decode((string) $row['muscle_groups'], true);

            return [
                'id'                   => (int) $row['id'],
                'exercise_name'        => $row['exercise_name'],
                'exercise_type'        => $row['exercise_type'],
                'muscle_groups'        => is_array($muscles) ? $muscles : [],
                'instructions'         => $row['instructions'],
                'notes'                => $row['notes'],
                'video_url'            => $row['video_url'],
                'thumbnail_url'        => $row['thumbnail_url'],
                'default_sets'         => (int) $row['default_sets'],
                'default_reps'         => (int) $row['default_reps'],
                'default_weight_kg'    => round((float) $row['default_weight_kg'], 2),
                'default_rest_seconds' => (int) $row['default_rest_seconds'],
                'metric'               => $row['metric'],
                'order_index'          => (int) $row['order_index'],
            ];
        }, $rows);
    }

    /**
     * BMI and the profile's cached weight both derive from a health row, so an
     * admin edit has to run the same recomputation a member's own edit does.
     */
    private function refreshHealthDerived(int $statId, int $fcUserId): void
    {
        global $wpdb;

        $height = $wpdb->get_var($wpdb->prepare(
            "SELECT height_cm FROM {$wpdb->prefix}fc_users WHERE id = %d",
            $fcUserId
        ));
        $weight = $wpdb->get_var($wpdb->prepare(
            "SELECT weight_kg FROM {$wpdb->prefix}fc_health_stats WHERE id = %d",
            $statId
        ));

        $bmi = null;
        if (null !== $height && (float) $height > 0 && null !== $weight) {
            $metres = ((float) $height) / 100;
            $bmi    = round(((float) $weight) / ($metres * $metres), 2);
        }

        $wpdb->update($wpdb->prefix . 'fc_health_stats', ['bmi' => $bmi], ['id' => $statId]);

        // Same recompute-don't-assign rule as HealthService: derive the profile
        // cache from the newest reading rather than from the row just touched.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fc_users u
                SET u.weight_kg = (
                        SELECT h.weight_kg FROM {$wpdb->prefix}fc_health_stats h
                         WHERE h.user_id = u.id AND h.weight_kg IS NOT NULL
                         ORDER BY h.record_date DESC, h.id DESC LIMIT 1
                    ),
                    u.updated_at = UTC_TIMESTAMP()
              WHERE u.id = %d",
            $fcUserId
        ));

        $this->announce($fcUserId);
    }

    /**
     * Moving a meal to another date leaves both days' rollups stale.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $fields
     */
    private function refreshMealDerived(array $before, array $fields): void
    {
        $userId  = (int) $before['user_id'];
        $oldDate = (string) $before['log_date'];
        $newDate = (string) ($fields['log_date'] ?? $oldDate);

        $nutrition = new \FitnessClub\Services\NutritionService();
        $nutrition->recomputeDay($userId, $newDate);

        if ($newDate !== $oldDate) {
            $nutrition->recomputeDay($userId, $oldDate);
        }
    }

    /**
     * Record that staff changed a member's own data (Q10), with before/after.
     *
     * Only the resources marked `audit` — those are the ones where the subject is
     * a member rather than the platform, and where the row is exempt from the
     * 12-month feed prune precisely because an audit trail with a retention
     * window is not an audit trail.
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private function auditIfNeeded(
        array $config,
        string $resource,
        int $id,
        array $before,
        array $after,
        int $actorAccountId,
        string $verb
    ): void {
        global $wpdb;

        if (!($config['audit'] ?? false)) {
            return;
        }

        $fcUserId = (int) ($before['user_id'] ?? 0);

        if ($fcUserId <= 0) {
            return;
        }

        $subjectAccountId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT account_id FROM {$wpdb->prefix}fc_users WHERE id = %d",
            $fcUserId
        ));

        if ($subjectAccountId <= 0) {
            return;
        }

        // Only the fields that actually moved, so the trail reads as a diff
        // rather than as a dump of every column.
        $changed = [];
        foreach ($after as $column => $value) {
            if (in_array($column, ['updated_at', 'source', 'last_edited_by_account_id'], true)) {
                continue;
            }

            $old = $before[$column] ?? null;

            if ((string) $old !== (string) $value) {
                $changed[$column] = ['before' => $old, 'after' => $value];
            }
        }

        $this->activity->audit(
            $subjectAccountId,
            $actorAccountId,
            'admin.' . str_replace('-', '_', $resource) . '.' . $verb,
            sprintf(
                /* translators: 1: resource name, 2: verb */
                __('An administrator %2$s a %1$s record', 'fitnessclub'),
                $resource,
                $verb
            ),
            '',
            $resource,
            $id,
            'deleted' === $verb ? ['before' => $before] : ['changes' => $changed]
        );
    }

    private function announce(int $fcUserId): void
    {
        do_action('fitnessclub/user_data_changed', $fcUserId, 'admin.edit');
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $query
     * @return array{0:string,1:array<int,mixed>}
     */
    private function where(array $config, array $query): array
    {
        global $wpdb;

        $clauses = ['1=1'];
        $params  = [];

        $search = trim((string) ($query['q'] ?? ''));

        if ('' !== $search && [] !== ($config['search'] ?? [])) {
            $ors = [];

            foreach ($config['search'] as $expr) {
                $ors[]    = $expr . ' LIKE %s';
                $params[] = '%' . $wpdb->esc_like($search) . '%';
            }

            $clauses[] = '(' . implode(' OR ', $ors) . ')';
        }

        foreach ($config['filters'] ?? [] as $name => $filter) {
            if (!isset($query[$name]) || '' === $query[$name] || null === $query[$name]) {
                continue;
            }

            $value = $query[$name];

            switch ($filter['op']) {
                case 'gte':
                    $clauses[] = $filter['expr'] . ' >= %s';
                    $params[]  = (string) $value;
                    break;
                case 'lte':
                    $clauses[] = $filter['expr'] . ' <= %s';
                    $params[]  = (string) $value;
                    break;
                case 'like':
                    $clauses[] = $filter['expr'] . ' LIKE %s';
                    $params[]  = '%' . $wpdb->esc_like((string) $value) . '%';
                    break;
                case 'in_subquery':
                    $clauses[] = $filter['expr'] . ' IN (' .
                        str_replace('{p}', $wpdb->prefix, $filter['sub']) . ')';
                    $params[]  = (int) $value;
                    break;
                default:
                    $clauses[] = $filter['expr'] . ' = %s';
                    $params[]  = (string) $value;
            }
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * @param array<string,mixed> $config
     */
    private function select(array $config): string
    {
        global $wpdb;

        return str_replace('{p}', $wpdb->prefix, implode(', ', $config['select']));
    }

    /**
     * @param array<string,mixed> $config
     */
    private function joins(array $config): string
    {
        global $wpdb;

        return str_replace('{p}', $wpdb->prefix, implode(' ', $config['joins'] ?? []));
    }

    /**
     * The sort column and direction, resolved through the registry.
     *
     * An unrecognised key falls back to the default rather than reaching the
     * query: this is the one place a request could otherwise put a string into
     * an identifier position.
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $query
     * @return array{0:string,1:string}
     */
    private function sort(array $config, array $query): array
    {
        [$defaultKey, $defaultDirection] = $config['default_sort'];

        $key       = (string) ($query['sort'] ?? $defaultKey);
        $direction = strtolower((string) ($query['order'] ?? $defaultDirection));

        $expr = $config['sort'][$key] ?? $config['sort'][$defaultKey];

        return [$expr, 'asc' === $direction ? 'ASC' : 'DESC'];
    }

    /**
     * Coerce a payload to the registry's writable columns and types.
     *
     * Anything not declared writable is dropped silently rather than rejected:
     * the client posts the row it was given, ids and derived columns included,
     * and refusing the whole write because `client_count` came back would make
     * every form fail.
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function clean(array $config, array $payload, bool $isCreate, bool $allowEmpty = false): array
    {
        $fields = [];

        foreach ($config['writable'] as $column => $type) {
            if (!array_key_exists($column, $payload)) {
                continue;
            }

            $fields[$column] = $this->coerce($type, $payload[$column]);
        }

        if ($isCreate) {
            foreach ($config['required'] ?? [] as $column) {
                if (!isset($fields[$column]) || '' === $fields[$column]) {
                    throw new DomainException(
                        'fc_field_required',
                        sprintf(
                            /* translators: %s: field name */
                            __('%s is required.', 'fitnessclub'),
                            str_replace('_', ' ', $column)
                        ),
                        400
                    );
                }
            }
        }

        if ([] === $fields && !$isCreate && !$allowEmpty) {
            throw new DomainException(
                'fc_nothing_to_update',
                __('Nothing to change.', 'fitnessclub'),
                400
            );
        }

        return $fields;
    }

    private function coerce(string $type, $value)
    {
        switch ($type) {
            case 'int':
                return null === $value || '' === $value ? null : (int) $value;
            case 'int_or_null':
                return null === $value || '' === $value || 0 === (int) $value ? null : (int) $value;
            case 'decimal':
                return null === $value || '' === $value ? null : round((float) $value, 2);
            case 'bool':
                return (int) (bool) $value;
            case 'date':
                return $this->date($value);
            case 'url':
                return $this->url($value);
            case 'textarea':
                return $this->textarea($value);
            case 'json_list':
                return $this->jsonList($value);
            case 'json_object':
                return $this->jsonObject($value);
            default:
                return $this->text($value);
        }
    }

    private function text($value): string
    {
        return sanitize_text_field((string) ($value ?? ''));
    }

    private function textarea($value): ?string
    {
        if (null === $value) {
            return null;
        }

        $clean = sanitize_textarea_field((string) $value);

        return '' === $clean ? null : $clean;
    }

    private function url($value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $clean = esc_url_raw((string) $value);

        return '' === $clean ? null : $clean;
    }

    private function date($value): ?string
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', trim($value));

        return $date && $date->format('Y-m-d') === trim($value) ? $date->format('Y-m-d') : null;
    }

    /**
     * A key/value map stored as JSON — the plan feature-flag editor (W3.2).
     *
     * Accepts an object or a JSON string, because the editor may submit either
     * a parsed object or the raw text an administrator typed. **Invalid JSON is
     * refused rather than stored**: `features` is what `EntitlementService`
     * merges, and a plan whose features silently became `null` would quietly
     * drop every paying member on it to the free tier.
     */
    private function jsonObject($value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (!is_array($decoded)) {
                throw new DomainException(
                    'fc_invalid_json',
                    __('The feature flags are not valid JSON.', 'fitnessclub'),
                    400
                );
            }

            return wp_json_encode($decoded);
        }

        if (!is_array($value)) {
            throw new DomainException(
                'fc_invalid_json',
                __('The feature flags must be an object.', 'fitnessclub'),
                400
            );
        }

        return wp_json_encode($value);
    }

    /**
     * A list stored as JSON. Accepts an array or a comma-separated string,
     * because the form sends one and an import sends the other.
     */
    private function jsonList($value): ?string
    {
        if (null === $value) {
            return null;
        }

        $items = is_array($value)
            ? $value
            : array_map('trim', explode(',', (string) $value));

        $items = array_values(array_filter(array_map(
            fn($item): string => $this->text($item),
            $items
        ), static fn(string $item): bool => '' !== $item));

        return wp_json_encode($items);
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $config, array $row): array
    {
        $out = [];

        foreach ($row as $key => $value) {
            if (in_array($key, ['muscle_groups', 'equipment', 'media_gallery'], true)) {
                $decoded   = json_decode((string) $value, true);
                $out[$key] = is_array($decoded) ? $decoded : [];

                continue;
            }

            if (null === $value) {
                $out[$key] = null;

                continue;
            }

            // Ints and decimals come back from wpdb as strings; the client's
            // types say number, and `"78.50" > 80` is false in JavaScript for
            // reasons nobody wants to debug on a chart axis.
            $out[$key] = is_numeric($value) && !in_array($key, ['barcode', 'phone'], true)
                ? (str_contains((string) $value, '.') ? (float) $value : (int) $value)
                : $value;
        }

        if (($config['audit'] ?? false) && isset($row['user_id'])) {
            $out['edited_by_staff'] = ($row['source'] ?? 'manual') === 'admin';
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function row(array $config, int $id): array
    {
        global $wpdb;

        $sql = sprintf(
            'SELECT %s FROM %s%s %s %s WHERE %s.id = %%d LIMIT 1',
            $this->select($config),
            $wpdb->prefix,
            $config['table'],
            $config['alias'],
            $this->joins($config),
            $config['alias']
        );

        $params = array_merge($config['select_params'] ?? [], [$id]);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers from the registry, values bound.
        $row = $wpdb->get_row($wpdb->prepare($sql, ...$params), ARRAY_A);

        if (!is_array($row)) {
            throw new DomainException(
                'fc_record_not_found',
                __('That record does not exist.', 'fitnessclub'),
                404
            );
        }

        return $row;
    }

    /**
     * @return array<string,mixed>
     */
    private function config(string $resource): array
    {
        $config = ResourceRegistry::get($resource);

        if (null === $config) {
            throw new DomainException(
                'fc_unknown_resource',
                __('There is no such resource.', 'fitnessclub'),
                404
            );
        }

        return $config;
    }
}
