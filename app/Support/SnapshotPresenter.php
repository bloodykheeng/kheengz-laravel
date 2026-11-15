<?php

namespace App\Support;

use App\Contracts\HasSnapshotSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Turns raw model attributes into the display-ready values frozen onto an audit entry.
 *
 * Everything here happens at write time and the result is stored. Resolving a foreign key
 * when the entry is read would let a later rename rewrite history, so the trail would claim
 * the user picked a name they never saw.
 *
 * Two envelopes, both consumed generically by the frontend:
 *
 *   snapshot: ['field' => ['label' => ..., 'type' => ..., 'value' => ...]]
 *   changes:  ['field' => ['label' => ..., 'type' => ..., 'old' => ..., 'new' => ...]]
 *
 * A `relation` value is `['id' => ..., 'label' => ...]`: the id stays machine-linkable and
 * the label stays readable once nobody remembers the ULID.
 *
 * See docs/snapshot-schema.md.
 */
class SnapshotPresenter
{
    /**
     * The schema a model declares, or an empty array when it declares none.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schemaFor(?Model $model): array
    {
        return $model instanceof HasSnapshotSchema ? $model::snapshotSchema() : [];
    }

    /**
     * A flat `field => {label, type, value}` map of the values given.
     *
     * Fields absent from the schema fall back to a humanised label and a string type, so a
     * schema that has drifted behind a new column cannot silently drop it from the trail.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array<int, string>  $exclude
     * @param  string  $consumer  Which reader this is being built for. Gates `only`.
     * @return array<string, mixed>
     */
    public static function snapshot(array $values, array $schema, array $exclude = [], string $consumer = 'audit'): array
    {
        $out = [];

        foreach ($values as $field => $value) {
            if (in_array($field, $exclude, true)) {
                continue;
            }

            $entry = $schema[$field] ?? null;

            if ($entry && ($entry['hidden'] ?? false)) {
                continue;
            }
            if ($entry && ! self::visibleTo($entry, $consumer)) {
                continue;
            }
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            // A declared collection renders as rows, not a scalar.
            if (self::isList($entry)) {
                $out[$field] = self::listSnapshot($field, $value, $entry, $consumer);

                continue;
            }

            // A declared object is a nested record: the same envelope, one level in.
            if (self::isObject($entry)) {
                $nested = self::objectSnapshot($field, $value, $entry, $consumer);

                if ($nested !== null) {
                    $out[$field] = $nested;
                }

                continue;
            }

            $type = $entry['type'] ?? 'string';

            $out[$field] = [
                'label' => $entry['label'] ?? self::humanise($field),
                'type' => $type,
                'value' => self::format($value, $entry, $type),
            ];
        }

        return self::ordered($out, $schema);
    }

    /**
     * A `field => {label, type, old, new}` map for the fields that actually changed.
     *
     * @param  array<string, mixed>  $before  Raw attribute values before the write.
     * @param  array<string, mixed>  $after  Raw attribute values after the write.
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array<int, string>  $exclude
     * @param  string  $consumer  Which reader this is being built for. Gates `only`.
     * @return array<string, mixed>
     */
    public static function changes(array $before, array $after, array $schema, array $exclude = [], string $consumer = 'audit'): array
    {
        $out = [];

        foreach ($after as $field => $newValue) {
            if (in_array($field, $exclude, true)) {
                continue;
            }

            $entry = $schema[$field] ?? null;

            if ($entry && ($entry['hidden'] ?? false)) {
                continue;
            }
            if ($entry && ! self::visibleTo($entry, $consumer)) {
                continue;
            }

            $oldValue = $before[$field] ?? null;

            // A declared collection is diffed row by row, matched on identity, so
            // an edited row reads as "changed" rather than "removed + added".
            if (self::isList($entry)) {
                $diff = self::listDiff($field, $oldValue, $newValue, $entry, $consumer);

                if ($diff !== null) {
                    $out[$field] = $diff;
                }

                continue;
            }

            // A declared object diffs its own sub-fields, and disappears when none moved.
            if (self::isObject($entry)) {
                $diff = self::objectDiff($field, $oldValue, $newValue, $entry, $consumer);

                if ($diff !== null) {
                    $out[$field] = $diff;
                }

                continue;
            }

            // Loose-ish compare on the RAW values: an int 1 written back as string "1" is
            // not a change anyone wants to read about, but null vs "" is.
            if (self::sameValue($oldValue, $newValue)) {
                continue;
            }

            $type = $entry['type'] ?? 'string';

            $out[$field] = [
                'label' => $entry['label'] ?? self::humanise($field),
                'type' => $type,
                'old' => self::format($oldValue, $entry, $type),
                'new' => self::format($newValue, $entry, $type),
            ];
        }

        return self::ordered($out, $schema);
    }

    /** A schema entry that describes a collection rather than a single value. */
    private static function isList(?array $entry): bool
    {
        return is_array($entry) && ($entry['kind'] ?? null) === 'list';
    }

    /** A schema entry that describes a nested record rather than a single value. */
    private static function isObject(?array $entry): bool
    {
        return is_array($entry) && ($entry['kind'] ?? null) === 'object';
    }

    /**
     * A nested record's values, presented through its own `fields` sub-schema.
     *
     * An object is a snapshot one level in, so labels, ordering, `hidden`, `only` and a
     * `kind: list` sub-field all work inside it unchanged. There is no recursion risk:
     * `fields` is a literal array on the model and cannot refer back to itself.
     *
     * Returns null when nothing survived, so an object of entirely hidden fields does not
     * leave a titled but empty section behind.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private static function objectSnapshot(string $field, mixed $value, array $entry, string $consumer): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $fields = self::snapshot($value, $entry['fields'] ?? [], [], $consumer);

        if ($fields === []) {
            return null;
        }

        return [
            'label' => $entry['label'] ?? self::humanise($field),
            'kind' => 'object',
            'fields' => $fields,
        ];
    }

    /**
     * A nested record's before and after, holding only the sub-fields that moved.
     *
     * When nothing inside moved the whole object is omitted rather than recorded as an
     * empty section.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private static function objectDiff(string $field, mixed $before, mixed $after, array $entry, string $consumer): ?array
    {
        $fields = self::changes(
            is_array($before) ? $before : [],
            is_array($after) ? $after : [],
            $entry['fields'] ?? [],
            [],
            $consumer,
        );

        if ($fields === []) {
            return null;
        }

        return [
            'label' => $entry['label'] ?? self::humanise($field),
            'kind' => 'object',
            'fields' => $fields,
        ];
    }

    /**
     * The columns a list renders, in schema order, minus the hidden ones.
     *
     * `identity` is almost always paired with `hidden`: the id is what rows are matched on,
     * and showing a ULID in the trail helps nobody.
     *
     * @param  array<string, mixed>  $entry
     * @return array<int, array{key: string, label: string, type: string}>
     */
    private static function listColumns(array $entry, string $consumer = 'audit'): array
    {
        $columns = [];

        foreach (($entry['item'] ?? []) as $key => $spec) {
            if (($spec['hidden'] ?? false) || ! self::visibleTo($spec, $consumer)) {
                continue;
            }

            $columns[] = [
                'key' => $key,
                'label' => $spec['label'] ?? self::humanise($key),
                'type' => $spec['type'] ?? 'string',
            ];
        }

        return $columns;
    }

    /** The item key rows are matched on, or null when the list declares none. */
    private static function identityKey(array $entry): ?string
    {
        foreach (($entry['item'] ?? []) as $key => $spec) {
            if ($spec['identity'] ?? false) {
                return $key;
            }
        }

        return null;
    }

    /**
     * A collection's rows, normalised to arrays.
     *
     * A `current` closure may legitimately return scalars. `(array) 'Health'` matches no
     * column key and would freeze a row of blanks, so a scalar is mapped onto the first
     * declared column instead.
     *
     * @param  array<int, array{key: string, label: string, type: string}>  $columns
     * @return array<int, array<string, mixed>>
     */
    private static function listRows(mixed $value, array $columns): array
    {
        if (! is_array($value)) {
            return [];
        }

        $scalarKey = $columns[0]['key'] ?? 'value';

        return array_values(array_map(
            fn ($row) => is_array($row) || is_object($row) ? (array) $row : [$scalarKey => $row],
            $value,
        ));
    }

    /**
     * One row reduced to its visible, formatted columns.
     *
     * The identity value rides along even though its column is hidden, for the reason a
     * scalar `relation` freezes both: the label is what a person reads and the id is what
     * keeps the entry linkable once nobody remembers which row "Health" was.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, array{key: string, label: string, type: string}>  $columns
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function listRow(array $row, array $columns, array $entry, ?string $identity = null): array
    {
        $out = [];

        foreach ($columns as $column) {
            $spec = $entry['item'][$column['key']] ?? null;
            $out[$column['key']] = self::format($row[$column['key']] ?? null, $spec, $column['type']);
        }

        // Appended, never prepended: a consumer showing only the first column of a
        // row must land on something readable, not a ULID.
        if ($identity !== null && ! array_key_exists($identity, $out) && isset($row[$identity])) {
            $out[$identity] = (string) $row[$identity];
        }

        return $out;
    }

    /**
     * How many rows a list may write before it is truncated.
     *
     * An audit entry is a JSON column rather than a table: a period that activates 300 votes
     * would embed 300 rows on every edit. Past this, rows are cut and the remainder recorded
     * as a count, so the entry stays honest about what it dropped.
     *
     * Override per entry with `'max' => n`.
     */
    private const LIST_MAX_ROWS = 50;

    /**
     * How much of a collection to record.
     *
     *   summary  the rows themselves, capped (default)
     *   ids      identity values only, for lists that are large but linkable
     *   count    a number, for lists too large to be worth reading
     *
     * @param  array<string, mixed>  $entry
     */
    private static function listStrategy(array $entry): string
    {
        $strategy = $entry['strategy'] ?? 'summary';

        return in_array($strategy, ['summary', 'ids', 'count'], true) ? $strategy : 'summary';
    }

    /**
     * Cut a row set to the cap, reporting what was dropped.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows: array<int, array<string, mixed>>, overflow: int}
     */
    private static function capRows(array $rows, array $entry): array
    {
        $max = max(1, (int) ($entry['max'] ?? self::LIST_MAX_ROWS));

        return [
            'rows' => array_slice($rows, 0, $max),
            'overflow' => max(0, count($rows) - $max),
        ];
    }

    /**
     * The identity values of a row set, for `strategy: ids`.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private static function rowIds(array $rows, ?string $identity): array
    {
        if ($identity === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (array $row) => isset($row[$identity]) ? (string) $row[$identity] : null,
            $rows,
        )));
    }

    /**
     * A created record's collection: the rows it was recorded with.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function listSnapshot(string $field, mixed $value, array $entry, string $consumer = 'audit'): array
    {
        $columns = self::listColumns($entry, $consumer);
        $identity = self::identityKey($entry);
        $rows = self::listRows($value, $columns);
        $strategy = self::listStrategy($entry);

        $out = [
            'label' => $entry['label'] ?? self::humanise($field),
            'kind' => 'list',
            'strategy' => $strategy,
            'total' => count($rows),
        ];

        if ($strategy === 'count') {
            return $out;
        }

        if ($strategy === 'ids') {
            $out['ids'] = self::rowIds($rows, $identity);

            return $out;
        }

        $capped = self::capRows($rows, $entry);

        $out['columns'] = $columns;
        $out['rows'] = array_values(array_map(
            fn (array $row) => self::listRow($row, $columns, $entry, $identity),
            $capped['rows'],
        ));
        $out['overflow'] = $capped['overflow'];

        return $out;
    }

    /**
     * A collection's before and after, matched on identity.
     *
     * Returns null when nothing moved, so an unchanged list never appears in the trail.
     *
     * `unchanged` is a count rather than the rows: a district serves all nine service areas,
     * and reprinting the seven that did not move buries the one that did.
     *
     * Without an identity key there is nothing to match on, so rows are compared whole and
     * an edit reads as removed plus added. Declaring `identity` is what buys "changed".
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private static function listDiff(string $field, mixed $before, mixed $after, array $entry, string $consumer = 'audit'): ?array
    {
        $columns = self::listColumns($entry, $consumer);
        $identity = self::identityKey($entry);

        $beforeRows = self::listRows($before, $columns);
        $afterRows = self::listRows($after, $columns);

        $key = static function (array $row, int $index) use ($identity): string {
            // No identity declared: fall back to the whole row, so a change reads
            // as removed + added rather than silently pairing unrelated rows.
            return $identity !== null && isset($row[$identity])
                ? (string) $row[$identity]
                : md5(json_encode($row).$index);
        };

        $beforeByKey = [];
        foreach ($beforeRows as $i => $row) {
            $beforeByKey[$key($row, $i)] = $row;
        }

        $afterByKey = [];
        foreach ($afterRows as $i => $row) {
            $afterByKey[$key($row, $i)] = $row;
        }

        $added = [];
        $removed = [];
        $changed = [];
        $unchanged = 0;

        foreach ($afterByKey as $k => $row) {
            if (! array_key_exists($k, $beforeByKey)) {
                $added[] = self::listRow($row, $columns, $entry, $identity);

                continue;
            }

            $old = self::listRow($beforeByKey[$k], $columns, $entry, $identity);
            $new = self::listRow($row, $columns, $entry, $identity);

            if ($old == $new) {
                $unchanged++;

                continue;
            }

            $changed[] = ['old' => $old, 'new' => $new];
        }

        foreach ($beforeByKey as $k => $row) {
            if (! array_key_exists($k, $afterByKey)) {
                $removed[] = self::listRow($row, $columns, $entry, $identity);
            }
        }

        if ($added === [] && $removed === [] && $changed === []) {
            return null;
        }

        $strategy = self::listStrategy($entry);

        $out = [
            'label' => $entry['label'] ?? self::humanise($field),
            'kind' => 'list',
            'strategy' => $strategy,
            'unchanged' => $unchanged,
            'counts' => [
                'added' => count($added),
                'removed' => count($removed),
                'changed' => count($changed),
            ],
        ];

        // A count-only list still says how much moved and in which direction,
        // which is the part that matters when the rows are too many to read.
        if ($strategy === 'count') {
            return $out;
        }

        // Each side is capped independently: 200 additions must not crowd out the
        // one removal, which is usually the interesting half.
        $addedCap = self::capRows($added, $entry);
        $removedCap = self::capRows($removed, $entry);
        $changedCap = self::capRows($changed, $entry);

        $out['columns'] = $columns;
        $out['added'] = $addedCap['rows'];
        $out['removed'] = $removedCap['rows'];
        $out['changed'] = $changedCap['rows'];
        $out['overflow'] = [
            'added' => $addedCap['overflow'],
            'removed' => $removedCap['overflow'],
            'changed' => $changedCap['overflow'],
        ];

        return $out;
    }

    /**
     * Reorders to match the schema's own field order, so an entry reads like the form rather
     * than however the request serialised its JSON.
     *
     * Fields with no schema entry keep their relative order at the end.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, mixed>>  $schema
     * @return array<string, mixed>
     */
    private static function ordered(array $values, array $schema): array
    {
        if (empty($schema)) {
            return $values;
        }

        $rank = array_flip(array_keys($schema));

        uksort($values, fn ($a, $b) => ($rank[$a] ?? PHP_INT_MAX) <=> ($rank[$b] ?? PHP_INT_MAX));

        return $values;
    }

    /**
     * Whether an entry is meant for this consumer.
     *
     * `only` limits an entry to certain readers: `['audit']` keeps `created_by` out of an
     * approval diff, where provenance is noise, without hiding it from the trail.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function visibleTo(array $entry, string $consumer): bool
    {
        $only = $entry['only'] ?? null;

        return $only === null || in_array($consumer, (array) $only, true);
    }

    /**
     * @param  array<string, mixed>|null  $entry
     */
    private static function format(mixed $value, ?array $entry, string $type): mixed
    {
        // An enum-cast attribute arrives as the enum itself; the trail stores its value.
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if ($value === null || $value === '') {
            return $type === 'boolean' ? false : null;
        }

        return match ($type) {
            'relation' => self::relation($value, $entry),
            'enum' => $entry['enum'][$value] ?? (string) $value,
            'date' => self::date($value, 'd M Y'),
            'datetime' => self::date($value, 'd M Y H:i'),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'number' => is_numeric($value) ? 0 + $value : (string) $value,
            'decimal' => is_numeric($value)
                ? number_format((float) $value, (int) ($entry['decimals'] ?? 2))
                : (string) $value,
            'money' => is_numeric($value) ? number_format((float) $value, 2) : (string) $value,
            'percentage' => is_numeric($value) ? number_format((float) $value, 2).'%' : (string) $value,
            'file' => self::relativeFile($value),
            'list' => is_array($value) ? $value : [$value],
            default => is_scalar($value) ? (string) $value : $value,
        };
    }

    /**
     * A foreign key resolved to `['id' => ..., 'label' => ...]`.
     *
     * A missing label, the related row being gone or no resolver declared, still records the
     * id: an unresolvable reference is better than no reference.
     *
     * @param  array<string, mixed>|null  $entry
     * @return array<string, mixed>
     */
    private static function relation(mixed $value, ?array $entry): array
    {
        $label = null;

        if ($entry && isset($entry['resolve']) && is_callable($entry['resolve'])) {
            $label = ($entry['resolve'])($value);
        }

        return ['id' => (string) $value, 'label' => $label !== null && $label !== '' ? (string) $label : null];
    }

    /**
     * A stored file path with no scheme or host, so a frozen entry stays valid if the app
     * moves domain. The frontend rebuilds the public URL. See docs/file-storage-structure.md.
     */
    private static function relativeFile(mixed $value): string
    {
        $value = (string) $value;

        if (str_contains($value, '/storage/')) {
            $value = substr($value, strpos($value, '/storage/') + strlen('/storage/'));
        }

        return ltrim($value, '/');
    }

    private static function date(mixed $value, string $format): ?string
    {
        try {
            return Carbon::parse($value)->format($format);
        } catch (\Throwable) {
            return is_scalar($value) ? (string) $value : null;
        }
    }

    private static function sameValue(mixed $old, mixed $new): bool
    {
        if ($old === null || $new === null) {
            return $old === $new;
        }

        if (is_scalar($old) && is_scalar($new)) {
            return (string) $old === (string) $new;
        }

        return $old == $new;
    }

    private static function humanise(string $key): string
    {
        return (string) Str::of($key)->replace('_', ' ')->title();
    }
}
