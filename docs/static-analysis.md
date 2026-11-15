# Static analysis: Larastan and Pint

**Status:** decision record · `phpstan.neon`.

> **In Kheengz there is no baseline.** GiftNest parks the findings it inherited in
> `phpstan-baseline.neon`; Kheengz started clean, so every finding is fixed at its source and
> `phpstan.neon` includes no baseline. Anything below about adding to or shrinking the baseline is
> GiftNest's history. Run: `vendor/bin/phpstan analyse` (level 5, 0 errors on 15 Nov 2026).

Two tools that do different jobs and are often confused. **Pint formats. Larastan
reasons.** Pint will happily format a file into perfect PSR-12 that crashes on
the first line it runs.

---

## 1. The bug that bought Larastan

`LegacyInspectionSeeder` called `freezeFor($votePeriod, ...)` four lines above the
`create()` that defines `$votePeriod`. A rebuilt database came up with **no
inspections at all** — no periods, no participations, no frozen scope, no
responses — and the failure was only found by rebuilding from empty and reading
the log.

**632 passing tests sat beside it.** They could not have caught it: the two
legacy seeders are the only code in this project outside *both* `DatabaseSeeder`
and the test suite, which is deliberate, because loading somebody else's history
is a decision to migrate rather than to install.

PHPStan finds it at **level 0**, the lowest there is:

```
299    Undefined variable: $votePeriod
[ERROR] Found 1 error
```

One error, the right line, in under a second. That is the whole argument.

---

## 2. `database/` is in `paths`, and Larastan's own example omits it

```neon
paths:
    - app/
    - database/
    - routes/
```

The upstream README shows `paths: [app/]`. Copying that would have excluded the
exact directory the bug lived in. **Analyse the code you actually ship**, and in
this project the seeders are shipped code: they build the register, the votes,
the departments and 17,842 delivery points.

`database/migrations/` is excluded. A migration is a linear history that is never
edited once applied, and it is the one place a column's type is being *declared*
rather than used, so the analysis has nothing to check it against.

---

## 3. Level 5 with a baseline, not level 0 and not level 9

- **Level 0 would be worse than nothing.** It catches this bug and almost nothing
  else, and a tool that passes on broken code teaches people to trust it.
- **Level 9 on a codebase this size** produces thousands of findings, which get
  ignored, which is the same as not having the tool.
- **Level 5 with a baseline** is the documented middle. 692 existing findings are
  parked in `phpstan-baseline.neon`; everything written from today is held to
  level 5.

The baseline is **parked, not forgiven**. Delete entries as they are fixed.
Regenerate it only when you mean to, because regenerating silently absolves
whatever broke since:

```bash
vendor/bin/phpstan analyse --generate-baseline --memory-limit=2G
```

Raising the level later is a matter of bumping `level:` and baselining the
difference, one level at a time.

### Clear the result cache before you trust a baseline

**`--generate-baseline` with a warm result cache produces an INCOMPLETE
baseline**, and this bit immediately. A first baseline was generated, `analyse`
reported `[OK] No errors`, and a cold run then found four real findings in a file
the baseline had never heard of. Repeat runs kept saying OK because the cache was
answering for the files it had already seen.

```bash
vendor/bin/phpstan clear-result-cache
vendor/bin/phpstan analyse --memory-limit=2G --generate-baseline
vendor/bin/phpstan clear-result-cache && vendor/bin/phpstan analyse --memory-limit=2G
```

The last line is the one that matters: a baseline you have not verified from cold
is a baseline that is quietly hiding whatever the cache skipped.

### A trait's findings are reported against the class that uses it

The same two errors were attributed to `LoggableTrait` on one run and to
`UserController` on the next. That is not flakiness: PHPStan reports a trait's
findings at the using class, so which class it names depends on what it analysed.
Do not chase the path in a baseline entry as though it were the definition.

---

## 4. Running them

```bash
composer lint      # pint --dirty, formats what you touched
composer analyse   # phpstan at level 5 against the baseline
composer check     # both, then the full test suite
```

`--memory-limit=2G` is not optional: the default 128M dies partway through a
project this size, and the failure looks like a crash rather than a finding.

**Pint still runs on every PHP change** (`vendor/bin/pint --dirty --format agent`).
That has not changed and is not replaced by any of this.

---

## 5. What each tool will and will not catch

| | Pint | Larastan |
| --- | --- | --- |
| Spacing, imports, ordering | yes | no |
| Undefined variable | no | yes, level 0 |
| Calling a method that does not exist | no | yes, level 0 |
| Wrong argument type | no | level 5 |
| A relation named in a query that the model lacks | no | yes, Larastan's own rule |
| Wrong *value* in a correctly typed argument | no | no |
| A seeder that writes the wrong rows | no | no |

The last two lines are the point: **static analysis does not replace the tests.**
It catches the class of fault the tests structurally cannot reach, which here was
a file no test imports.
