# Foreign Key Conventions

How to choose `ON DELETE` behaviour and write foreign keys in this project's
migrations. The SDS portal is an audit tool for government service delivery — the
records it holds are evidence, so the schema is built to refuse a delete rather
than quietly damage history.

---

## 1. The core principle

> **Never let a delete silently destroy or orphan data that still has meaning.**

When a row is deleted, every foreign key pointing at it must do one of three
things. Pick the one that matches the *relationship type*, not whatever is easiest:

| Relationship | Policy | Meaning |
| --- | --- | --- |
| **Composition** — the child is owned by, and meaningless without, the parent | `cascade` | Delete the children with the parent. |
| **Association / reference / lookup / audit** — the row merely *points at* something that exists independently | `restrict` | Block the parent's deletion while anything references it. |
| **Genuinely disposable link** — losing the link loses nothing recoverable | `set null` | Null the link, keep the row. **Rare. Always comment why.** |

### Why `restrict` is the default for references

- `set null` **orphans** data. An inspection period whose financial year has been
  nulled is a round of inspections that happened in no particular year — the
  record is now a lie.
- `cascade` from a *reference* **destroys history**. Delete one service area and a
  cascade could silently wipe every department that was never "owned" by it.
- `restrict` **protects integrity**: you cannot delete something still in use. You
  deal with the dependents first — deliberately, not by accident.

### The analogy

> A district belongs to its region, so a district may `cascade` from the region.
> But a **vote** that is *located in* a district is not owned by it — deleting the
> district must be **blocked** (`restrict`), because the vote still needs it.

Both of those relationships exist in this schema, which is exactly why the policy
has to be chosen per column rather than set once for the whole table.

---

## 2. Decision checklist

For every FK, ask in order:

1. **Is this row part of the parent — does it exist only to describe it?**
   (a sub-location, a per-parent detail row) → **`cascade`**.
2. **Does this row point at something that lives on its own — a lookup, a master
   record, another module's entity, or a `*_by` audit user?** → **`restrict`**.
3. **Would nulling this link genuinely lose nothing** (the value is duplicated
   elsewhere, or the link is peripheral and recomputable)? → **`set null` + a
   comment explaining exactly why.** If you cannot write that comment
   convincingly, it is a `restrict`.

**Audit columns (`created_by`, `updated_by`, …) are always `restrict`.** They
record *who did what*; that must never be lost, and a user who has acted in the
system should not be hard-deletable — deactivate them instead (`status =
inactive`).

---

## 3. Style: field up, foreign key down

Define the **column** with the rest of the schema (its `nullable()`, `index()` and
defaults live there), and declare the **foreign key constraint** in one explicit
block near the bottom of the table. Use the verbose, string-valued `onDelete(...)`
form so the policy is obvious at a glance.

```php
Schema::create('departments', function (Blueprint $table) {
    $table->ulid('id')->primary();
    $table->string('name')->index();
    $table->string('code')->index();
    $table->ulid('vote_id')->nullable();
    $table->ulid('service_area_id')->nullable();
    $table->ulid('created_by')->nullable();
    $table->ulid('updated_by')->nullable();

    // Foreign keys, contestanted and explicit.
    $table->foreign('vote_id')->references('id')->on('votes')->onDelete('restrict');
    $table->foreign('service_area_id')->references('id')->on('service_areas')->onDelete('restrict');
    $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
    $table->foreign('updated_by')->references('id')->on('users')->onDelete('restrict');
});
```

Avoid the fluent one-liner that hides the column definition, the nullability and
the delete policy inside a single chain — it mixes three concerns on one line:

```php
// Harder to scan: column, nullability and FK policy all tangled together.
$table->foreignUlid('vote_id')->nullable()->constrained()->nullOnDelete();
```

Notes:

- `->onDelete('restrict')` / `->onDelete('cascade')` / `->onDelete('set null')` —
  one consistent verbose form everywhere. Prefer these over `restrictOnDelete()` /
  `cascadeOnDelete()` / `nullOnDelete()`.
- Foreign keys auto-create an index; do not add a redundant single-column index on
  the FK column. (A *composite* index starting with the FK is still worth adding.)
- Vendor / framework migrations — the Spatie permission tables, the default
  `sessions` and `jobs` tables — keep their published style. Do not rewrite them.
- Columns are ULIDs here (`$table->ulid(...)`), so the referenced column is a ULID
  too. See [ulid-primary-keys.md](ulid-primary-keys.md).

---

## 4. Surface `restrict` errors as a clean 409

`restrict` means the database throws when someone tries to delete an in-use
record. Unhandled, that is a raw HTTP 500. It is converted once, globally, in
`bootstrap/app.php`:

```php
$exceptions->render(function (QueryException $e, Request $request) {
    $message = strtolower($e->getMessage());
    $isForeignKeyViolation = str_contains($message, 'foreign key');

    // The one FK failure that means "still in use" rather than "points at
    // something that is not there".
    $isParentStillReferenced = str_contains($message, 'cannot delete or update a parent row')
        || str_contains($message, 'still referenced from table');

    if (
        $request->is('api/*')
        && $isForeignKeyViolation
        && ($request->isMethod('DELETE') || $isParentStillReferenced)
    ) {
        return response()->json([
            'message' => 'This record is referenced by other records and cannot be deleted. Remove or reassign what points to it first.',
        ], 409);
    }
});
```

**The shape is Laravel's own `{ message }`**, not a JSON:API `errors[]` array. See
[removing-json-api.md](removing-json-api.md); the frontend's `useHandleMutationError`
reads `message`, so this reaches the user as a readable toast with no work per screen.

**The method is no longer the discriminator, and that was a real bug.** Gating on
`DELETE` looked safe, on the reasoning that the only FK violation a `DELETE` can raise
is "this parent row is still referenced", while an insert or update FK failure is a
different fault, a bad reference, that should keep its normal handling.

But **a `PUT` can remove a parent row too**. Saving a standard rewrote its whole scale,
so editing one that had been scored against raised MySQL 1451 on a `PUT`, and the gate
let it through as a raw 500: the officer got the SQL statement, the constraint name and
the connection details rendered in a toast. MySQL and Postgres both say in words which
of the two failures it is, so that phrase decides now. `DELETE` stays as the fallback for
SQLite, which says only "FOREIGN KEY constraint failed" and cannot tell them apart.

That is the backstop rather than the first line. A controller that knows what it is
about to break should refuse in words first: `StandardController` counts the answers
scored on a rung it is about to drop and returns a 422 naming the rung and the count.

---

## 5. This project's policies

### `cascade` — the location hierarchy, and a standard's bands

| Column | References | Why |
| --- | --- | --- |
| `districts.region_id` | `regions` | A district exists only inside its region. |
| `counties.district_id` | `districts` | Owned by the district. |
| `sub_counties.county_id` | `counties` | Owned by the county. |
| `parishes.sub_county_id` | `sub_counties` | Owned by the parish's sub-county. |
| `villages.parish_id` | `parishes` | Owned by the parish. |
| `standard_bands.standard_id` | `standards` | A band is part of the standard's definition, not a reference to it. |

Deleting a region really does mean "this region is not part of Uganda's
administrative map any more", and everything beneath it goes with it. That is
composition, so `cascade` is correct — but note it is also why the Regions delete
dialog warns about the size of what it removes.

`standard_bands` is the one row outside the location tree, and it is the same
reasoning: "Above 95% Compliance scores 4" is not a record that lives on its own, it
is one rung of the scale the standard *is*. Leaving orphaned bands behind would be
worse than deleting them, since nothing would ever reach them again. Note also that
this cascade can only fire for a standard nothing has been scored against:
`inspection_responses.standard_id` is `restrict`, so a standard with a single
recorded response cannot be deleted at all.

**The rest of the register stays `restrict`.** `standards.category_id`,
`categories.objective_id` and `objectives.service_area_id` are all references, not
composition. A category is not "part of" its objective in the way a band is part of
its standard: it holds its own code, its own standards, and its own recorded
history, and deleting an objective must be blocked rather than silently taking 8
categories and 60 standards with it.

### `restrict` — everything that is a reference

| Column | References | Why |
| --- | --- | --- |
| every `created_by` / `updated_by` | `users` | Audit trail. Never lose who did what. |
| `votes.administrative_unit_id` | `administrative_units` | The unit the vote belongs to. |
| `votes.region_id` … `votes.village_id` | locations | Where the vote sits. A vote with a nulled district is unplaceable. |
| `departments.vote_id` | `votes` | The budget entity the department reports under. |
| `departments.service_area_id` | `service_areas` | Which of the nine sectors it delivers. |
| `inspection_periods.financial_year_id` | `financial_years` | A period is identified by its year ("Q1 of FY 2025/26"). |
| `users.administrative_unit_id` | `administrative_units` | The user's scope. |
| `users.vote_id` | `votes` | The user's scope. |
| `users.service_area_id` | `service_areas` | The user's scope. |
| `users.department_id` | `departments` | The user's scope. |

The four `users.*` columns deserve the extra note. `UserController::roleBasedRules()`
*requires* a vote for a Vote Admin and a department for an Inspector. Under
`set null` — which is what this project originally had — deleting a department
would leave an Inspector account holding the Inspector role with no department to
inspect: full permissions, no scope, and no error anywhere to notice it by.
`restrict` blocks the delete until those users are reassigned, which is the
decision a human should be making anyway.

### `set null`

**None.** Nothing in this schema has a link whose loss costs nothing. If you add
one, comment the exact reason on the constraint, and add a row to this table.

---

## 6. Checking your work

```bash
# Every delete policy in the project, one per line:
grep -rn "onDelete(" database/migrations
```

Read the output against §5. Anything new that is `set null` without a comment
above it is a bug in the making.
