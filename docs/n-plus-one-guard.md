# The N+1 Guard (Eloquent strict lazy loading)

`Model::preventLazyLoading()` is enabled in `AppServiceProvider::guardAgainstLazyLoading()`.
A relation touched without being eager-loaded **throws in dev/CI/tests** and **logs a
warning in production**.

---

## 1. Why automate it

An N+1 is invisible at the call site. Nothing in

```php
foreach ($departments as $department) {
    $department->vote->name;   // one query. per row.
}
```

looks wrong, and a reviewer reading a 200-line controller will not spot the missing
`with('vote')`. The endpoint still returns the right data — it just issues 51 queries
instead of 2, and only at production data volumes does anyone notice.

This project had a specific landmine: **`UserResource` exposes a `role` attribute**
backed by the `roles` relation (`User::getRoleAttribute()` reads `$this->roles`). That
attribute is appended, so it fires on *every* serialization.

**It is closed on the MODEL now, not at the call sites.** `User::$with = ['roles']`.
The remedy this document used to give was "add `->with('roles')` to the query that
produced it", and that is right for an optional relation and wrong for this one: an
appended attribute is serialised every time, so `roles` is a hard dependency of
rendering a user rather than something a caller opts into. There are thirty-odd
places a user can be reached, and the rule drifted twice: first on the notification
recipient list, then on the **bell's own feed**, which eager-loads `createdBy` for the
author and threw the moment a notification had one. Chat writes an authored
notification per message, which is what finally surfaced it on a dashboard that had
only ever held system-written rows.

**`createdBy` and `updatedBy` must never join it**: they point back at User and would
recurse. They stay explicit at the call sites that render them.

Held by `UserSerialisationTest`. Note what that test had to get right to be worth
anything: **the feed must hold TWO authored notifications**. Laravel 13 guards only
models that arrive as a collection, so a feed holding one row eager-loads a single
author and goes green against the bug. An earlier draft did exactly that.

---

## 2. How it behaves

```php
Model::preventLazyLoading();

Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
    if (! $this->app->isProduction()) {
        throw new LazyLoadingViolationException($model, $relation);
    }

    Log::warning('Lazy loading violation (N+1 risk)', [
        'model' => $model::class,
        'relation' => $relation,
    ]);
});
```

| Environment | Behaviour | Rationale |
| --- | --- | --- |
| local, CI, tests | **throw** | The endpoint fails loudly, so the missing `with()` is added before merge. |
| production | **log** | A missed eager-load degrades performance; it must never 500 a real user's request. |

---

## 3. What actually trips it (Laravel 13 semantics)

The guard fires when the model was **loaded as part of a collection** — which is where
N+1 lives. A single model is exempt:

```php
$faqs = Faq::limit(3)->get();
foreach ($faqs as $faq) { $faq->createdBy; }   // ← throws (3 rows, 3 queries)

$faq = Faq::first();
$faq->createdBy;                               // ← allowed (one model, one query)
```

That is deliberate and worth knowing: **testing the guard with `Model::first()` will
make it look broken.** Always test with `->get()`.

---

## 4. Working with it

**In a list controller** — eager-load anything the resource will serialize:

```php
$query = QueryBuilder::for(User::class)
    ->with('roles')                       // the appended `role` attribute needs this
    ->allowedIncludes('vote', 'department', ...);
```

**In `show()`** — load the relations the single resource renders:

```php
$department->load(['vote', 'serviceArea', 'createdBy', 'updatedBy']);
```

**When you hit a violation**, do not reach for `Model::preventLazyLoading(false)`. The
exception names the model and the relation; add that relation to the `with()` /
`load()` on the query that produced it.

**`allowedIncludes` is not eager loading by itself.** Spatie QueryBuilder only loads an
include when the *client* asks for it via `?include=`. Anything a resource always
renders must be in an explicit `with()`.

---

## 5. Current state

Every list endpoint was probed with **no** `include` parameter — the case where a
resource might reach for an unloaded relation — and none trip the guard:

```
departments, districts, villages, parishes, inspection-periods, activity-logs,
users, faqs, votes, service-areas, financial-years
```

The JSON:API resources only emit relationship *linkage* (built from the foreign-key
column already on the row) unless `include=` asks for the record, which is why they
stay clean without eager-loading everything.
