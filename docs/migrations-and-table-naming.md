# Migrations and table naming

**Status:** convention · binding. Read before adding a table or a column.

Three rules, each settled after doing it the other way first.

---

## 1. One table per migration

A migration creates exactly one table. `criteria` and `criterion_bands` were once one file
because the bands "belong to" the criterion; they are two files now, `create_criteria_table`
and `create_criterion_bands_table`, and the rollback of one is the rollback of one.

The reason is the history. A migration is a record of a schema change that is never edited once
applied, and a file that creates two tables is two changes with one name: it cannot be read from
the file list, it cannot be rolled back alone, and when the second table is later dropped the
first table's creation still carries its name.

## 2. A column added to an existing table gets its own migration, with its constraint

`users` is created first of all and knows nothing about institutions. `institution_id` is not
declared on the users table and constrained later; it is added, **column and foreign key
together**, in `add_institution_to_users_table`, which runs after `institutions` exists:

```php
Schema::table('users', function (Blueprint $table) {
    $table->ulid('institution_id')->nullable()->index();

    $table->foreign('institution_id')->references('id')->on('institutions')->onDelete('restrict');
});
```

This is the HURIS pattern (`add_location_columns_to_users_table`) with one change: `restrict`
where HURIS has `set null`, because that is this project's rule for a reference (see
[foreign-key-conventions.md](foreign-key-conventions.md)). Works the same with ULIDs; only the
column type differs.

The alternative, declaring the column on the parent and adding the key later, was tried first and
rejected: the users migration then names a table that does not exist yet, and a reader has to
find the second migration to learn what the column points at.

## 3. Pivot tables: singular when they are nothing but the two keys, plural otherwise

Laravel's own convention for a `belongsToMany` intermediate table is **singular, the two model
names in alphabetical order**: `role_user`. SDS keeps that exactly for every pivot that is
nothing but the pair, and goes plural the moment a table carries something of its own. The rule
here is the same:

| Table holds | Name | Key | Deletes | Model |
| --- | --- | --- | --- | --- |
| The two keys, and at most timestamps | singular, alphabetical: `department_standard` | composite primary on the pair, no ULID | `cascade` both sides | none; written by `sync()` |
| Anything of its own: an attribute (`is_lead`), a status, dates, a ULID, endpoints | plural, like any table: `contestant_users`, `edition_institutions`, `edition_users` | the pair, or a ULID when the row has an identity | `cascade` from the composition side, `restrict` from a reference | as needed |

So:

- `contestant_users` is plural because it carries `is_lead`. It still has **no surrogate id**: the
  pair is the primary key and it is written by `sync()`, so a not-null `id` with no default
  would fail every attach. It has no model; the contestant's audit entry names the members.
- `edition_institutions` is plural and has a ULID, a status, `registered_at`, a model and
  endpoints: an ordinary table that happens to join two others.
- A future `topic_tag` holding only `topic_id` and `tag_id` would be singular.

Why the pair as the key rather than a ULID on `contestant_users`: "which contestants is this student in" is
answered by the index on `user_id`, "who is in this contestant" by the primary key, and a third column
that nothing ever looks up is a column that will one day be looked up by mistake.

The SDS examples this was checked against: `department_standard`, `question_topic`,
`administrative_unit_service_area`, `standard_location_level` (singular, keys only);
`inspection_period_votes`, `notification_viewed_bies` (plural, own identity).
