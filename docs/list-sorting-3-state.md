# 3-State Column Sort & Deterministic Pagination

**Status:** reference · applies to every sortable server-backed list table in this system.

A **3-state sort** is a column header that cycles on repeated clicks:

```
click 1        click 2        click 3
ascending  →  descending  →  default (server's natural order)  →  (back to ascending)
```

The third state — **remove the sort, return to the server's default order** — is what makes
this more than a two-state toggle.

## 1. The wire contract

One query param, Spatie QueryBuilder style:

| State | `sort` value | On the wire |
| --- | --- | --- |
| Ascending | `created_at` | `?sort=created_at` |
| Descending | `-created_at` | `?sort=-created_at` |
| Default | *(absent)* | *(param omitted)* |

**Design rule (do not break):** the default state is the **ABSENCE** of `sort`, never a magic
value like `sort=none`. Absence keeps the server branch trivial — param present → sort; absent
→ leave `defaultSort()` alone — and needs no sentinel kept in sync on both sides.

List state lives in the **URL search params** (shareable, back-button friendly), not component
state. See [`hooks/useListParams.ts`](../../sdstool-nextjs/hooks/useListParams.ts).

## 2. Frontend

`useListParams()` reads `sort` straight from the URL and derives what PrimeReact needs:

```ts
const sort = searchParams.get("sort") ?? "";

// No `sort` param → no active column → no arrow. The neutral state reads correctly.
const sortField = sort ? sort.replace(/^-/, "") : undefined;
const sortOrder: 1 | -1 = sort.startsWith("-") ? -1 : 1;
```

`columnSortToParam()` maps the header click back onto the param:

```ts
export function columnSortToParam(sortField, sortOrder): string {
  if (!sortField || !sortOrder) return "";   // 3rd click → drop the param
  return sortOrder === -1 ? `-${sortField}` : String(sortField);
}
```

`pushParams` deletes any key whose value is empty, so `sort: ""` removes the param and the
list falls back to the server's order. That is the whole mechanism.

Three details that matter:

- **No client-side `DEFAULT_SORT` fallback.** A fallback re-applies the sort the instant the
  param clears, which makes the neutral state unreachable — that was the original bug here.
  `useListParams` deliberately has none.
- **Sortable columns use the RAW API field** as their `field` (`name`, `status`, `created_at`)
  — the same key the server sorts by, so no client→server translation layer is needed. If a
  column's *displayed* value differs from its *sortable* value, sort by the API field and
  render the display value in the column `body`.
- **Sorting is the column header only.** `FiltersButton` must never carry a sort dropdown; two
  controls over one param inevitably disagree.

`PrimeReactDataTable` already sets `removableSort`, which is what produces the third
(removed) state.

## 3. Backend

```php
QueryBuilder::for(District::class)
    ->allowedSorts('name', 'status', 'created_at')   // ← the whitelist
    ->defaultSort('-created_at')                     // ← the "default" order
    ->orderBy('id', 'desc');                         // ← unique tiebreak (see §5)
```

1. **Whitelist** every sortable field in `allowedSorts(...)`. Spatie rejects anything else, so
   an arbitrary or malicious `sort` can never reach the query. The frontend's sortable columns
   must be a subset of this list.
2. **`defaultSort('-…')`** defines the natural order used when `sort` is absent. This is why
   "default" needs no client value — the server owns it. Usually `-created_at`, so a
   freshly created row shows at the top.
3. Spatie sorts at the query level, **before pagination** — the sorted set is what gets paged.

## 4. Pagination

Sort and pagination are coupled: **reset to page 1 whenever the sort changes**. Otherwise the
user is left on "page 3" of a freshly reordered list, which means nothing. `setFilters()` does
this for filters; every `onSort` handler passes `page: DEFAULT_PAGE` explicitly.

## 5. The trailing `orderBy('id', 'desc')` — why every list has one

**Symptom:** paging through a list, the same record appears on more than one page while another
is skipped entirely. Locally everything looks fine.

**Cause:** a sort key that is **not unique** does not define a total order. `-created_at` alone
ties whenever two rows share a timestamp — bulk imports, seeders, or simply two records saved
in the same second. This app is full of those: the location hierarchy alone was seeded in bulk
(71,217 villages), so ties are the norm, not the exception. SQL guarantees **no** order for tied
rows, and the database is free to return them differently for each `LIMIT/OFFSET` request. A
tied row lands on page 1 for one request and page 2 for the next: it shows twice, and another
row is pushed off. Local looks fine only by luck — small data, warm buffer pool, simpler plan.

**Fix:** end the sort chain with a unique column, the primary key:

```php
->defaultSort('-created_at')
->orderBy('id', 'desc');   // two rows can never tie now
```

The full ordering becomes `created_at DESC, id DESC`. Even with identical timestamps the ULID
breaks the tie the same way on every request, so pagination is stable.

**Rule of thumb: the last `orderBy` in the chain must be a unique column, pointing the same way
the list runs.** Uniqueness buys stability; direction buys a readable order inside each tie, and
the two are separate failures (see below). When a user picks an explicit `sort`, Spatie applies
it *before* this trailing `orderBy`, so the tiebreak keeps working in every sort state, not just
the default.

This is why every index method in `app/Http/Controllers/Api/V1` ends the same way. Do not
"tidy it away" as redundant — it is load-bearing.

**Order matters within the chain, and getting it wrong is silent.** Spatie applies `defaultSort`
when the query runs, so a trailing `orderBy` lands *second* in the `ORDER BY` and stays a
tiebreak. Chain it before `defaultSort` and it becomes the **primary** sort while the code still
reads as though the default is in charge.

### It drifted anyway, which is why there is now a test

This section has said all of the above since the document was written, and three controllers
still shipped without the tiebreak: `QuestionTopicController`, `ServiceDeliveryPointTypeController`
and `UnitOfMeasureController`. A rule that lives only in prose is checked only when somebody
remembers to read the prose.

`tests/Feature/Api/ListSortStabilityTest.php` now enforces it: every controller that calls
`defaultSort` must either sort on the primary key itself or end on `orderBy('id', 'desc')`, and
the tiebreak must come after the default. `JobController` is the one legitimate exemption,
because its default *is* `-id`.

### The DIRECTION drifted too, and stability hid it

Uniqueness is all stability needs, so the sweep above passed for a long time while eleven
controllers chained `orderBy('id')` **ascending** under a `-created_at` default. Nothing paged
twice, so nothing failed. What it did instead was **reverse the order inside every tie**: a batch
of rows written in one second came back oldest-first within a newest-first list.

Measured on the seeded registry rather than argued: 17,845 delivery points carry only **213**
distinct `created_at` values, and one of those seconds holds **125 rows**. A whole page could
read back to front.

**A ULID is why this is worth getting right rather than merely tidy.** Its first 48 bits are the
millisecond, so the id is a *higher resolution copy of the timestamp that tied*. Decoding those
125 rows gives **125 distinct milliseconds across a 989ms span, no two sharing one**, so the id
does not merely break the tie arbitrarily — it recovers the true insert order that a
second-precision `created_at` threw away. Pointing it the wrong way is the difference between
recovering that order and inverting it.

(The idea came from auto-increment keys, where `id` ascending *is* insert order. It survives the
move to ULIDs, and gains resolution in the process.)

**So: `desc`, everywhere, including the positional lists.** On `sort_order` and `code` the
tiebreak almost never fires — `sort_order` is unique per parent and the parent is the first sort
key, and `code` is unique outright — so one rule everywhere costs nothing and is one less thing
to get wrong. A second test, *"breaks ties in the same direction the list runs"*, holds it.

**Three places keep an ascending `orderBy('id')` and must:**

| Where | Why |
| --- | --- |
| `MaintainsPositions::resequence()` | it **assigns** positions 1..n; descending would hand tied rows their numbers backwards |
| `->orderBy('id')->pluck('id')` in the standard-allotment controllers | builds an id **set** for a `sync`; order reaches no screen |
| `whereKey(...)->orderBy('id')->get()` | fetches named rows, not a paginated list |

The direction test matches only chains that begin a line, which is how a list query is formatted
here, so those three inline forms are outside it by construction rather than by exception.

**The delivery point registry is what makes this concrete rather than theoretical.** 17,842 rows
share **210** distinct `created_at` values, because a seeder writes a hundred a second. Sorted on
that column alone, ninety-nine rows at a time sit in whatever order the planner chose that
request. The same registry also had `defaultSort('name')` where every other operational list uses
`-created_at`, which is a different fault with the same effect on the reader: nothing newly added
was ever visible, since a new point lands wherever the alphabet puts it among 17,842.

## 6. Checklist

- [ ] URL param `sort` = `field` (asc) / `-field` (desc) / **absent** (default). No sentinel.
- [ ] `sort` read straight from the search params — no client-side default fallback.
- [ ] Sortable columns' `field` = the raw API field, a subset of the backend `allowedSorts`.
- [ ] Every sort change resets to page 1.
- [ ] Backend whitelists with `allowedSorts(...)` and sets `defaultSort('-…')`.
- [ ] The chain ends in a **unique tiebreaker** (`orderBy('id', 'desc')`) so pagination stays
      stable when the default-sort column ties.
- [ ] The tiebreaker is chained **after** `defaultSort`, not before it.
- [ ] `ListSortStabilityTest` passes. It checks both of the above across every V1 controller, so
      this box is ticked by running the tests rather than by reading the code.
