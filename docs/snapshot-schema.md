# `snapshotSchema()` — describing a record once, for every consumer

**Status:** reference · portable. Nothing here is specific to this system; the examples are.

A model declares **how it is described to a human** in one place. The audit trail freezes
changes and snapshots through it; an approval engine can build a before/after diff from the
same declaration. Describe the record once, or watch the two descriptions drift apart.

---

## 1. The problem it solves

Without it, an audit entry looks like this:

```json
{ "region_id": "01kz3yv86acjqfrpk275mgr4jg" }
```

Nobody can read that. Six months later, in an investigation, that ULID means nothing to
anyone — and an update reads ULID → ULID, which is worse than no entry at all because it
looks like a record was kept.

With it:

```json
{ "region_id": { "label": "Region", "type": "relation",
                 "old": { "id": "01kz3yv…", "label": "BUGISU" },
                 "new": { "id": "01kz3ym…", "label": "KIGEZI" } } }
```

Both halves matter. The **label** is what a human needs; the **id** keeps the entry
machine-linkable so the trail can still jump to that record.

## 2. The rule that makes it an audit trail

> **Resolve at write time. Freeze the result. Never resolve at read time.**

If the frontend resolved `region_id` when the entry was *displayed*, renaming a region would
retroactively change what the trail claims happened — it would say the user picked a name
that did not exist when they picked it. A record that changes when the world changes is a
cache, not an audit trail.

The same rule governs stored file paths, enum labels and formatted dates. Everything is
resolved once, by `SnapshotPresenter`, at the moment of the write.

## 3. One schema, more than one reader

The point of declaring it on the model rather than in the audit trail is that **the audit
trail is not the only thing that needs to describe a record to a human.** An approval
queue showing a maker's proposed change is asking the same question, and if it declares
its own field map the two answers drift: the trail says "Governance tier", the approval
screen says "Type", and the same edit reads as two different things.

So `snapshotSchema()` is written for the record, not for the trail. Two mechanisms keep it
honest when readers disagree:

```php
// The reader asks for itself. Defaults to 'audit', so nothing existing changes.
SnapshotPresenter::changes($before, $after, $schema, consumer: 'approval');

// The schema says which readers a field is for.
'internal_remark' => ['label' => 'Internal remark', 'only' => ['audit']],
```

`only` is an allowlist: absent means every reader. Something that is real content to an
investigator and noise to an approver is a property of the *field*, not of the reader,
which is why it is declared once here rather than re-filtered at each call site.

`only` is for **content**, not bookkeeping. `created_by`, `updated_at`, `remember_token`
and the rest never reach the schema at all: `LoggableTrait` strips them for every reader
through `auditIgnoredFields` before the presenter is called, which is why no model declares
them and none should.

This is also the compatibility contract. A model carried into another codebase brings its
whole description with it, and any presenter reading `snapshotSchema()` must support every
key in §4 or that model silently loses part of itself. `kind: object` exists for exactly
that reason: nothing here declares one yet, but a schema that cannot express a nested
record is not a portable description of a record.

## 4. Declaring a schema

```php
class District extends Model implements HasSnapshotSchema
{
    public static function snapshotSchema(): array
    {
        return [
            'name' => ['label' => 'District name', 'type' => 'string'],
            'region_id' => [
                'label' => 'Region',
                'type' => 'relation',
                'resolve' => fn ($id) => Region::query()->whereKey($id)->value('name'),
            ],
            'status' => ['label' => 'Status', 'type' => 'enum', 'enum' => [
                'Active' => 'Active',
                'Inactive' => 'Inactive',
            ]],
        ];
    }
}
```

### Entry keys

| Key | Meaning |
| --- | --- |
| `label` | Human title. Defaults to a humanised field name. |
| `type` | `string` · `text` · `enum` · `relation` · `date` · `datetime` · `boolean` · `number` · `decimal` · `money` · `percentage` · `file` · `link` · `list`. **That list is closed**, and `SnapshotPresenter::format()` stringifies anything else through its `default` arm without complaining. Nine declarations across eight models said `integer`, which is not one of them, so every score and every position rendered as `"4"` rather than `4` and nothing failed. Whole numbers are `number`. Held by `SnapshotSchemaTest`, prose here having not been enough |
| `resolve` | `fn ($id) => ?string` — required for `type: relation` |
| `enum` | `[stored value => label]` — for `type: enum` |
| `decimals` | Precision for `type: decimal`, default 2. `money` and `percentage` are always 2. |
| `hidden` | Drop from every consumer (ids, tokens, `lastlogin`) |
| `only` | Limit to certain consumers, e.g. `['audit']` |
| `kind` | `list` for a collection, `object` for a nested record. Omit for a scalar. See §5 and §6. |
| `current` | `fn (Model $m) => mixed` reading a value that is not a column on this table. Required with either `kind`, optional on a scalar (§7). |
| `item` | Column specs for a collection's rows, same keys as above plus `identity`. |
| `fields` | Sub-field specs for a nested record, same keys as above. |
| `strategy` | `summary` (default) · `ids` · `count`. How much of a collection to record. |
| `max` | Row cap for a collection, default 50. |

`type: list` and `kind: list` are **not** the same thing. `type: list` is a raw array kept
verbatim in one value; `kind: list` is a structured collection with columns, a diff and a
cap. Reach for the second one.

**Field order is presentation order.** Entries are emitted in schema order, so a snapshot
reads like the form rather than however the request serialised its JSON.

### Ordering and omissions

- A field **absent from the schema** is still recorded, with a humanised label and
  `string` type. A schema lagging behind a new column must never silently drop it.
- A field marked `hidden` is dropped everywhere.
- Bookkeeping (`id`, timestamps, `created_by`/`updated_by`, `remember_token`) and secrets
  are stripped by the writer before the schema is consulted, so a model neither declares
  nor hides them. **Never rely on the schema alone to keep a password out of the log.**
  Omission is the second lock, not the first.

## 5. Do not walk the object graph

The tempting next step is recursion: snapshot the region, its districts, their counties…
Do not.

Measured on this system's live data, one region holds:

```
8 districts · 15 counties · 104 sub-counties · 445 parishes · 2,863 villages
```

A recursive snapshot would embed 3,435 records into a single JSON column, on every edit.
Circular relations (User → Department → Users) make it worse.

**One level, opt-in.** A related collection is included only when declared, and only at the
depth declared:

```php
'districts' => [
    'label'    => 'Applies to districts',
    'kind'     => 'list',
    'strategy' => 'summary',              // summary | ids | count
    'max'      => 50,                     // row cap, default 50
    'current'  => fn (self $m) => $m->districts()->orderBy('name')
        ->get(['id', 'name'])->all(),
    'item' => [
        'id'   => ['identity' => true, 'hidden' => true],
        'name' => ['label' => 'District', 'type' => 'string'],
    ],
],
```

`current` is what makes it work without controller code. `getChanges()` only ever reports
columns, so a pivot edit is invisible to an attribute diff: `captureOriginal()` and
`buildChanges()` call `current` themselves, before and after the write. Declare it once on
the model and every controller that edits the pivot logs it correctly.

Each `item` key is a column, and takes the same entry keys as a top-level field:
`label`, `type`, `hidden`, `only`, plus `resolve`/`enum` for its type. Rows are formatted
through them exactly like scalars, so a list column can itself be a `relation` or a `date`.
Hidden columns are dropped from `columns` and never rendered.

`current` may also return **scalars** rather than rows, such as a list of payment dates or
codes. They are mapped onto the first visible column, so a one-column list needs no
wrapping:

```php
'couponDates' => [
    'label'   => 'Coupon dates',
    'kind'    => 'list',
    'current' => fn (self $m) => $m->coupon_dates,        // ['2026-01-31', …]
    'item'    => ['value' => ['label' => 'Date', 'type' => 'date']],
],
```

### 5.1 What a collection writes

**On a create or delete** (`snapshot`), the rows as they stood:

```json
"serviceAreas": {
  "label": "Service areas", "kind": "list", "strategy": "summary", "total": 3,
  "columns": [ { "key": "name", "label": "Service area", "type": "string" } ],
  "rows":    [ { "name": "Health", "id": "01kz…" },
               { "name": "Education and Sports", "id": "01kz…" } ],
  "overflow": 1
}
```

**On an update** (`changes`), a row-level diff instead:

```json
"serviceAreas": {
  "label": "Service areas", "kind": "list", "strategy": "summary",
  "counts": { "added": 1, "removed": 1, "changed": 1 },
  "unchanged": 1,
  "columns":  [ { "key": "name", "label": "Service area", "type": "string" } ],
  "added":    [ { "name": "Water and Environment", "id": "01kz…" } ],
  "removed":  [ { "name": "Education and Sports", "id": "01kz…" } ],
  "changed":  [ { "old": { "name": "Heath",  "id": "01kz…" },
                  "new": { "name": "Health", "id": "01kz…" } } ],
  "overflow": { "added": 0, "removed": 0, "changed": 0 }
}
```

`columns` travels with the entry rather than being looked up at read time, for the same
reason labels are frozen: a schema edited next year must not rewrite what this entry claims
was shown. **Render `columns`, not the row keys**: a row carries its identity value as an
extra key that is deliberately not a column (§5.2).

**An unchanged collection is omitted entirely.** `listDiff` returns null when nothing was
added, removed or changed, so a column-only edit does not carry a list at all. Reordering
alone is not a change either, because rows are matched by identity, not position.

`unchanged` is only ever a **count**. A district serves all nine service areas; reprinting
the seven that did not move buries the one that did.

### 5.2 Identity, and what it buys

`identity` marks the `item` key rows are matched on. With it, editing a row reads as
**changed**, with both sides. Without it, rows are hashed whole, so the same edit reads as
*removed + added*, which is legible but loses the fact that it was one row and not two. It is
usually paired with `hidden`: matching on a ULID is useful, printing one is not.

The identity value is still **written into every frozen row**, appended after the visible
columns even when its column is hidden. This is §1's argument applied one level down: a
scalar `relation` freezes `{ id, label }` because the label is what a human reads and the
id is what keeps the entry machine-linkable. A row that froze only "Health" is unlinkable
the moment a second service area is named Health, or that one is renamed. It is appended
rather than prepended so a consumer showing only a row's first value lands on something
readable instead of a ULID.

### 5.3 Caps, and how they are reported

An audit entry is a JSON column, not a table. A period that activates 300 votes would
otherwise embed 300 rows on **every** edit, and the trail becomes the largest thing in the
database while being no more useful.

- Rows past `max` (default 50) are cut, and the remainder recorded as `overflow`, so the
  entry stays honest about what it dropped instead of silently lying about the total.
- A diff caps each bucket **independently**, so 200 additions cannot crowd out the one
  removal, which is usually the interesting half. Hence `overflow` is an object there and
  an integer on a snapshot.
- `strategy` narrows it further when even a capped list is too much:

| `strategy` | Snapshot writes | Diff writes |
| --- | --- | --- |
| `summary` | `columns` + `rows` + `overflow` | `columns` + `added`/`removed`/`changed` + `overflow` |
| `ids` | `ids` only | same as `summary` |
| `count` | `total` only | `counts` + `unchanged` only |

`ids` deliberately does nothing on the diff path. A snapshot holds *everything* the record
had, which is what gets large; a diff holds only what moved, which is already small. `count`
still applies to both, because a mass re-sync can move thousands of rows at once.

The question a schema answers is *"what did this record look like"*, not *"what did the
whole database look like"*. Anything deeper belongs on that other record's own entry.

## 6. Nested records: `kind: object`

Not every related value is a collection. A `hasOne` detail row, or a JSON column with its
own labelled sub-fields, is **one nested record**, and flattening it into `security_quantity`
and `security_price` throws away the fact that it is a thing with a name.

```php
'security' => [
    'label'   => 'Security detail',
    'kind'    => 'object',
    'current' => fn (self $t) => $t->security?->only([
        'quantity', 'price', 'maturity_date', 'broker_id',
    ]),
    'fields' => [
        'quantity'      => ['label' => 'Face value', 'type' => 'number'],
        'price'         => ['label' => 'Cost price', 'type' => 'money'],
        'maturity_date' => ['label' => 'Maturity date', 'type' => 'date'],
        'broker_id'     => [
            'label' => 'Broker',
            'type' => 'relation',
            'resolve' => fn ($id) => Broker::query()->whereKey($id)->value('name'),
        ],
        'updated_by'    => ['hidden' => true],
    ],
],
```

**An object is the same envelope one level in.** It writes
`{ label, kind: 'object', fields: { … } }`, where `fields` holds exactly what the top level
holds, so everything already described keeps working inside it: labels, schema ordering,
`hidden`, `only`, and a `kind: list` sub-field rendering as its own collection. A consumer
that can render the top level renders an object by recursing, with no new cases.

Two rules it inherits, both the same ones scalars follow:

- **Only what moved.** A diff carries only the sub-fields that changed, and an object where
  nothing moved is omitted entirely rather than written as a titled empty section.
- **Absent stays absent.** A `current` returning null (no security detail on this
  transaction) is recorded as absent on that side of the diff, not as a row of blanks.

There is no recursion guard and none is needed: `fields` is a literal array written on the
model, so it cannot refer back to itself. Depth is bounded by what somebody typed.

## 7. Diff from what was written, not what was asked for

```php
$before = $this->captureOriginal($model);   // BEFORE the save
$model->update($validated);
$changes = $this->buildChanges($model, $before);   // AFTER it
```

`buildChanges` reads `$model->getChanges()` — what Eloquent actually wrote — not the request
payload. Those differ more often than expected: a mutator, a cast, or a status flipped by a
side effect all land in the row without ever appearing in `$validated`.

**Values that are not columns** are the gap this leaves, and a Spatie role is the sharpest
case. It lives in a pivot table, so granting someone System Admin, the most
security-relevant edit in most systems, does not appear in `getChanges()` at all.

That is what `current` closes, for any shape. Declare the closure and the value is read
before and after the write and diffed like everything else, with no controller code:

```php
'role' => [
    'label'   => 'Role',
    'type'    => 'string',
    'current' => fn (self $m) => $m->roles()->pluck('name')->last(),
],
```

A `current` with no `kind` is a plain scalar that happens to live elsewhere. Read through
the relation **query**, never the loaded relation, or a capture taken straight after
`syncRoles()` returns the cached old value and the diff quietly says nothing changed.

An **undeclared** off-table value is still invisible, and silently so. If a module edits
something that is not a column on its own table, declare it or log it by hand. Declaring it
is better than logging it by hand for a reason that is easy to underrate: a hand-built
envelope in a controller covers the one path somebody remembered. The role above was
hand-built on create and update, and so was missing from every user deletion, which is the
one entry where it could never be recovered from anywhere else.

**One thing no schema can see:** writes to *other* records. Activating one inspection period
deactivates the others; those rows changed without the request ever naming them, so they
need their own entry.

## 8. Destructive actions deserve the most detail, not the least

The instinct is to record less on a delete because the record is gone. It is exactly
backwards — after the delete, the entry is the **only** surviving account of it.

- **Single delete** → snapshot the record *before* it goes. `buildModelSnapshot()` takes its
  declared collections along with its columns, because afterwards there is nothing left to
  re-read the pivot from.
- **Cascading delete** → also count what goes with it. A database-level `ON DELETE CASCADE`
  fires no ORM events, so descendants vanish with no entries of their own. Counting first is
  the only record they existed.
- **Bulk delete** → snapshot every record, not a column of ids. Cap the detail (50 records,
  cascade counts only for small batches) so one action cannot write a megabyte of JSON, and
  state the true total alongside the truncation.

## 9. What this deliberately does not do

Recorded so the next person does not have to rediscover the reasoning. The prior art is
`alpha-fund-laravel`'s `ApprovalPresentation`, which this grew out of. Every declaration
key that presenter supports is supported here, deliberately, per §3: a schema that cannot
express what another system's models already declare is not portable.

**The formatted values are richer, and that is the one thing a port must know.** That
presenter stringifies everything: a relation becomes `"Stanbic"` or `"#B1"`, a boolean
becomes `"Yes"`. Here a relation stays `{ id, label }` and a boolean stays a real boolean,
because §1 is unwilling to throw the id away and a UI should decide whether false reads as
"No" or as an unticked box. Anything reading these values must handle both shapes, which is
one adapter, once, at the consumer. Going the other way loses data and cannot be adapted
back, so this direction is the deliberate one.

**Nested lists stay nested.** That presenter promotes a collection declared inside an
object up into a sibling contestant, because its frontend renders a flat list of contestants. Here it
stays where it was declared, inside the object's `fields`, since a consumer that recurses
does not need the flattening and the structure matches how somebody wrote it.

**No trial-apply.** That presenter builds an amendment's "after" by applying the change
inside a transaction it always rolls back. That is the right technique for an approval
**preview**, where the future state does not exist yet and cannot be persisted. An audit
trail has a genuine before and after, because the write already happened, so trial-applying
here would be an elaborate way to reproduce what `getChanges()` already knows.

Its companion rule is worth carrying anyway, in case approvals ever land: **file work must
never sit inside a trial-applied method.** A rollback undoes database writes; it does not
un-delete a file off disk.

**Freeze the process, not only the values.** The same system snapshots the *approval steps*
that applied to a request at submission time, so editing a workflow later cannot rewrite
what path a past request took. Rule §2 is not only about labels: if an inspection's scoring
bands or step order can be edited, the entry has to record which version it was judged
against, or the trail silently re-judges history.

## 10. Adopting it

1. `implements HasSnapshotSchema` and write `snapshotSchema()`.
2. Hand-write it. Inference from `*_id` → relation → `name` is tempting and wrong: the
   moment a model needs an enum label, a date format, or a label that is not `name`, the
   magic becomes an exception, and exceptions to invisible rules are the worst kind.
3. Give a `current` closure to anything the record is edited through that is not a
   column on its own table: `kind: list` for a collection, `kind: object` for a detail
   row or JSON body, no kind for a scalar. Nothing else is needed, the trait reads them
   before and after the write on its own.
4. Describe the record, not the audit trail. Mark a field `only: ['audit']` when it is
   genuinely provenance rather than content, and leave everything else open (§3).
5. Add `cascadeBlastRadius()` if anything cascades from this model.
6. Log by hand anything that is neither a column on this table nor a declared relation.
