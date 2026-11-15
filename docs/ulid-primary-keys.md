# ULID Primary Keys

Every table this application owns uses a ULID primary key instead of an
auto-incrementing integer.

---

## 1. Why

- **Ids are not guessable or countable.** `/api/v1/votes/7` invites someone to try
  `8`, and tells them the system holds at least seven votes. A ULID leaks neither.
- **Ids can be minted before insert.** Related rows can be built in memory and
  written in one transaction without a round trip to learn the parent's id.
- **Ids are unique across tables and environments.** Copying a row between
  environments, or merging data captured offline during a field inspection, cannot
  collide the way sequence numbers do.
- **They still sort by creation time.** A ULID's first 48 bits are a timestamp, so
  `ORDER BY id` is chronological — the one useful property auto-increment had, and
  the reason for choosing ULID over UUIDv4.

The cost is 26 characters per key instead of 8 bytes. For a schema of this size
that trade is worth taking.

---

## 2. How it is wired

**Migration** — the column is a ULID, and so is every FK pointing at it:

```php
Schema::create('departments', function (Blueprint $table) {
    $table->ulid('id')->primary();
    $table->ulid('vote_id')->nullable();
    // ...
    $table->foreign('vote_id')->references('id')->on('votes')->onDelete('restrict');
});
```

**Model** — `HasUlids` mints the key on insert:

```php
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class Department extends Model
{
    use HasUlids;
}
```

`HasUlids` also sets `$incrementing = false` and `$keyType = 'string'`, so route
model binding, `find()` and relationships all behave.

**Morphs** — polymorphic columns must match the key type of what they point at:

```php
$table->ulidMorphs('tokenable');              // personal_access_tokens
$table->nullableUlidMorphs('subject', 'subject');  // activity_log
```

---

## 3. The exceptions

Three tables keep integer keys, deliberately:

| Table | Why |
| --- | --- |
| `jobs`, `job_batches`, `failed_jobs` | Framework-owned. The queue driver writes to them directly, not through a model, so there is no `HasUlids` to mint a key. |
| `personal_access_tokens` | Sanctum owns the model and it does not use `HasUlids`. Only the `tokenable` morph changes, because it points at our ULID users. |
| `roles`, `permissions` | Spatie's published migration. Only `model_has_roles.model_id` / `model_has_permissions.model_id` become ULIDs, because those point at users. |

**This is a real trap.** The first pass at this conversion made
`personal_access_tokens.id` a ULID, and every login then failed with
`Field 'id' doesn't have a default value` — the table expected the model to supply
a key and Sanctum's model never does. Before ULID-ing a table, check that an
application model with `HasUlids` is what inserts into it.

`activity_log` is the opposite case: it *is* ULID, which works only because
`config/activitylog.php` points `activity_model` at `App\Models\Activity`, a
subclass that adds `HasUlids`. Remove that config line and Spatie inserts through
its own integer-keyed model, and every write to the audit log breaks.

---

## 4. Consequences elsewhere

- **Validation** — id rules are `['string', 'exists:table,id']`, never `integer`.
  The queue endpoints are the exception and keep `integer`, matching their tables.
- **Broadcast events** — `?string $recordId`, not `?int`.
- **Channel authorisation** — compare as strings. `(int) $user->id === (int) $id`
  collapses every ULID to `0`, which authorises any user for any other user's
  channel. `routes/channels.php` casts with `(string)`.
- **Frontend** — JSON:API renders `id` as a string anyway, so the change is mostly
  invisible. The one place it shows is bulk delete: payloads send
  `{ "ids": ["01k…", "01k…"] }` and must not coerce with `Number()`.

---

## 5. Adding a new table

1. `$table->ulid('id')->primary();`
2. `use HasUlids;` on the model.
3. FK columns are `$table->ulid('other_id')` with an explicit `onDelete(...)` —
   see [foreign-key-conventions.md](foreign-key-conventions.md).
4. Any morph is `ulidMorphs` / `nullableUlidMorphs`.
5. Validation rules for those ids use `string`, not `integer`.
