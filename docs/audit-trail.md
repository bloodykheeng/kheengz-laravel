# Audit Trail (activity log)

**Status:** reference · every write in this system is expected to leave an entry.

The record of **who did what, to which record, what changed, when, and from where**. A trail
that only says "District was updated" is not an audit trail — it cannot answer any question an
investigator actually has. Every entry here is built to answer six:

| Question | Where it lives |
| --- | --- |
| **Who** | `causer_id` / `causer_type` + `properties.created_by` / `created_by_email` |
| **What** | `event` (`district_updated`) + `description` + `properties.outcome` |
| **Which record** | `subject_type` / `subject_id`, and `log_name` = the module |
| **What changed** | `properties.changes` (updates) or `properties.snapshot` (creates/deletes) |
| **What else died** | `properties.cascade` — descendants a cascading delete took with it |
| **When** | `created_at`, pinned when the action happened |
| **Where** | `properties.ip`, country/city/region/lat/long/timezone, device/platform/browser |

Plus the trace: `properties.request_id`, `method`, `url`, `route`, and the sanitised
`properties.request` body.

**Values are resolved and frozen at write time** through each model's `snapshotSchema()` —
see [snapshot-schema.md](snapshot-schema.md), which is the important half of this design. A
foreign key is stored as `{id, label}`, never a bare ULID:

```json
"region_id": { "label": "Region", "type": "relation",
               "old": { "id": "01kz3yv…", "label": "BUGISU" },
               "new": { "id": "01kz3ym…", "label": "KIGEZI" } }
```

## 1. The shape of it

- **Storage** — Spatie `activitylog` writing through [`App\Models\Activity`](../app/Models/Activity.php)
  (ULID keys like every table we own; `config/activitylog.php` points `activity_model` here).
- **Writing** — never call Spatie's `activity()` directly. Controllers use
  [`LoggableTrait::logActivity()`](../app/Traits/LoggableTrait.php), which dispatches
  [`LogActivityJob`](../app/Jobs/LogActivityJob.php).
- **Reading** — [`ActivityLogsController@index`](../app/Http/Controllers/Api/V1/ActivityLogsController.php)
  (`GET /api/v1/activity-logs`), serialised by [`ActivityLogResource`](../app/Http/Resources/ActivityLogResource.php).
- **Retention** — `activitylog:clean` runs daily at 01:30 (`routes/console.php`), dropping rows
  older than `activitylog.clean_after_days` (365). The table only ever grows otherwise.

### `log_name` is the module, not the event

`log_name` holds the **module** — `District`, `User`, `Queue` — and `event` holds the specific
action. They are deliberately different: grouping by module is how the trail is browsed, and it
is derived from the subject's class so it can never drift from the record actually touched.
Callers with no model to point at (a login, an email that failed) pass `module` in the
properties instead; it is stored as `log_name` and stripped from the properties so the same
value is not shown twice.

## 2. Logging an action

```php
use App\Traits\LoggableTrait;   // in the controller

$this->logActivity(
    'district_created',                              // event
    "District '{$district->name}' was created.",     // description
    ['snapshot' => $this->buildSnapshot($validated, model: $district)],
    $district,                                       // subject → Module + Affected record
);
```

`outcome` is inferred from the event key: anything containing `fail` is recorded as `failed`,
everything else as `success`. Pass the fifth argument explicitly to override.

### Updates — capture before, diff after

```php
$before = $this->captureOriginal($district);   // ← BEFORE the save
$district->update($validated);

$this->logActivity('district_updated', "District '{$district->name}' was updated.",
    ['changes' => $this->buildChanges($district, $before)],
    $district);
```

The diff comes from `$model->getChanges()` — **what Eloquent actually wrote**, not the
request payload. A mutator, a cast, or a status flipped by a side effect all land in the row
without appearing in `$validated`; a trail built from the request would miss every one.

Only fields that actually changed are returned, so a no-op save logs no changes. The frontend
renders `changes` as a red/green Before/After table.

**Two things a column diff cannot see** (both live in this codebase):

- **A Spatie role** is a pivot row, not a column. `UserController::update` captures the
  previous role by hand and appends it to `changes` — granting System Admin is the most
  security-relevant edit here and the attribute diff is blind to it.
- **Writes to other records.** Activating an inspection period deactivates all the others;
  `InspectionPeriodController::update` logs an `inspection_period_auto_deactivated` entry
  naming each one, or those rows would go Inactive with nothing explaining why.

### Creates and deletes — a snapshot, not a diff

A create has no "before", so `buildSnapshot($validated, model: $model)` records a flat
`field => value` map rather than a misleading `(empty) → value` diff. It is ordered by the
model's `$fillable` so the entry reads in the same order as the form.

A delete uses `buildModelSnapshot($model)` **before** the row goes. Afterwards the entry is the
only surviving account of what the record held, so "Region 'X' was deleted" alone is not enough.

### Cascading deletes — count the blast radius

The location hierarchy is `onDelete('cascade')` **in the database**, which fires no Eloquent
events: descendants vanish with no entries and no broadcasts of their own. Measured on live
data, deleting one region destroys:

```
8 districts · 15 counties · 104 sub-counties · 445 parishes · 2,863 villages = 3,435 rows
```

`buildBlastRadius($model)` counts them first, via each model's `cascadeBlastRadius()`, so the
entry carries `cascade: {Districts: 8, …}`. Without it the most destructive action in the
system would have the least detail.

### Bulk deletes — name the records

```php
$context = $this->buildBulkDeleteContext(County::whereKey($ids)->get());   // ← before
County::destroy($ids);

$this->logActivity('county_bulk_deleted', count($ids).' counties were deleted.',
    array_merge(['module' => 'County'], $context));
```

Each record is snapshotted, because a column of bare ULIDs tells an investigator nothing.
Detail is capped at 50 records and cascade counts at 25, so one action cannot write a
megabyte of JSON; the true total is always recorded, and truncation is stated.

## 3. No bare ids in the readable sections

An auditor cannot read `01kz3yv86acjqfrpk275mgr4jg`. So a raw id is never a property of its
own — the entry already carries `subject_id` as a proper column for machine linking, and
every human-facing section names things instead:

| Was | Is now |
| --- | --- |
| `district_id: "01kz…"` | dropped — `subject_id` already holds it |
| `district_ids: ["01kz…", …]` | dropped — `records` names each one, `count` has the total |
The only ids left in `properties` are the `id` half of a `{id, label}` relation pair, which
is deliberate: the label is for the reader, the id keeps the entry linkable.

### `request` is the exception, on purpose

`properties.request` is kept **verbatim — ids, extra fields and all**. It is the one part of
the entry that is evidence rather than presentation:

```json
"snapshot": { "region_id": { "label": "Region", "value": { "id": "01kz…", "label": "BUGISU" } } },
"request":  { "name": "ZZ Req B", "status": "Active", "region_id": "01kz3yv86acjqfrpk275mgr4jg" }
```

`snapshot`/`changes` answer *"what happened, readably"*. `request` answers *"what exactly did
the client send"* — including fields that were ignored, rejected, or made no difference.
Resolving or trimming it would turn it into a second, worse copy of `snapshot`, and you
cannot know in advance which question it will end up answering. Secrets are still stripped
from it; nothing else is.

Queue jobs and audit rows are the deliberate exception — they have no human label, so their
id *is* the identifier.

## 4. What is never written

`LoggableTrait::$auditRedactKeys` strips `password`, `password_confirmation`,
`current_password`, `new_password`, `otp`, `token`, `_token`, `_method`, `access_token`,
`refresh_token`, `api_key` and `secret` from both the captured request body and the
changes/snapshot helpers. Uploaded files become a `[file: name]` marker so binary content never
lands in the log.

**`code` is deliberately not redacted.** Administrative units, departments and service areas all
carry a business `code` an investigator needs to see. Only genuine secrets belong in that list —
do not add a field just because the name sounds sensitive.

## 5. Why the write is queued

The "where" enrichment geolocates the IP through a third-party HTTP call and parses the
user-agent. Inline, that sat on the critical path of every create, update and delete. It now
runs in `LogActivityJob`, so the request returns immediately.

Two consequences worth knowing:

- **`occurredAt` is pinned by the caller**, not by the job. The queue may run seconds later, and
  "when it happened" is not "when we got round to writing it down".
- **A delete's subject no longer exists when the job runs.** `performedOn()` finds nothing, so
  the job writes `subject_type` / `subject_id` directly afterwards — otherwise deletions would be
  the one event with no Module, which is exactly the event you most want attributed.

Geolocation is cached per IP: a hit for a day (a location is stable), a miss for only ten
minutes, so a transient provider failure does not blank coordinates for 24 hours. Private and
reserved addresses (local dev, LAN) skip the lookup entirely.

### What is still synchronous, and why

Some work cannot be deferred: it reads state that the write is about to destroy. Measured on
this database:

| Work | Queries | Time |
| --- | --- | --- |
| Snapshot / diff on a create or update | 1 per relation field | <1 ms |
| Blast radius, single district | 4 | ~13 ms |
| Blast radius, single region | 5 | ~21 ms |
| Bulk context, 50 records | 50 | ~29 ms |

That is the floor. A delete cannot count its descendants after they are gone, and a diff
cannot read a value the update already overwrote. The costs are small next to the operation
being audited — a 50-row bulk delete cascades far more work than 29 ms — so they are left
alone rather than traded for a caching layer that could serve a stale label.

What *was* expensive is gone: the geolocation call is an external HTTP request that used to
sit on the critical path of every write.

**This means a stale queue worker stops the audit trail.** If entries are not appearing, check
`php artisan queue:work` before anything else — it is the recurring gotcha in this repo.

## 6. Correlation id

[`AssignRequestId`](../app/Http/Middleware/AssignRequestId.php) gives every API request a
`request_id`, stored on the audit entry, pushed into Laravel's log context so every `Log::` line
in that request carries it, and returned as an `X-Request-Id` response header. One action leaves
marks in the audit trail, the application log and the queue worker's log; without a shared id,
tying them together after an incident means guessing from timestamps.

An inbound `X-Request-Id` is honoured so a trace started upstream survives, but only when it
matches `[A-Za-z0-9-]{8,64}` — an arbitrary client value would otherwise poison unrelated logs.

## 7. Reading and filtering

`GET /api/v1/activity-logs`:

| Filter | Effect |
| --- | --- |
| `filter[search]` | partial match on `description` |
| `filter[log_name]` | exact module |
| `filter[event]` | exact event key |
| `filter[causer_id]` | everything one user did |
| `filter[subject_type]` | by affected model — short (`District`) or full class name |
| `filter[subject_id]` | **the full history of one record** |
| `filter[outcome]` | `success` / `failed` |
| `filter[start_date]` / `filter[end_date]` | date range on `created_at` |
| `sort` | `log_name`, `event`, `created_at` (default `-created_at`) |
| `include=causer` | eager-load the acting user |
| `all=true` | unpaginated — backs the Excel export |

`filter[subject_id]` is the one that matters most in an investigation: it reconstructs the entire
life of a single record, in order.

## 8. Frontend

`app/dashboard/(settings)/audit-trail/`:

- **`RecordsList`** — Module / Description / Performed by / When, with Affected record, Event and
  Outcome available as hidden columns. 3-state sortable headers
  (see [list-sorting-3-state.md](list-sorting-3-state.md)).
- **`RecordDetailsDialog`** — the entry's header (module, outcome, when), who/what/which record,
  then **Field changes** as a red/green Before/After table, **Recorded values** for
  creates and deletes, the **Submitted request**, **Who & where**, and the **Request trace**.
- **`helpers.ts`** — `fieldChanges` / `snapshotValues` / `requestPayload` / `outcomeOf` pull the
  structured keys out of `properties`; anything not matching the expected shape is ignored rather
  than rendered as `[object Object]`.

## 9. Adding a module

1. `implements HasSnapshotSchema` on the model + write `snapshotSchema()`
   ([snapshot-schema.md](snapshot-schema.md)). Add `cascadeBlastRadius()` if anything
   cascades from it.
2. `use LoggableTrait;` on the controller.
3. **Create** → `buildSnapshot($validated, model: $m)` + subject.
   **Update** → `captureOriginal()` before the save, `buildChanges()` after + subject.
   **Delete** → `buildModelSnapshot()` + `buildBlastRadius()` **before** the delete + subject.
   **Bulk** → `buildBulkDeleteContext()` before the delete + `module` key.
4. Log by hand anything that is not a column on this table (roles, writes to other records).
5. Nothing else. Module name, who, where, outcome, request body and correlation id are filled
   in for you — that uniformity is the whole point of going through the trait.

## 10. Related

- [snapshot-schema.md](snapshot-schema.md) — how a model describes itself; the half of this
  design that makes entries readable, and reusable by an approval engine later.
- [file-storage-structure.md](file-storage-structure.md) — why a stored file path is frozen,
  and what happens to a file when its row goes.
- [list-sorting-3-state.md](list-sorting-3-state.md) — the trail's own list behaviour.

---

## The trail is a copy beside the data, never the only home for it

**A fact the system needs lives in a table. The activity log is a copy.** Two
things follow, and both were broken until they were swept for deliberately.

### 1. Retention has to outlive the records the trail explains

`clean_after_days` was **365**, which is Spatie's default and was never a
decision here. `activitylog:clean` runs nightly.

These are **annual** inspections whose results are kept indefinitely and have to
be defensible years afterwards. The trail is where a register change is
explained: a standard renamed in 2026 is recorded here with before and after
through `snapshotSchema()`, and that is the **stated reason a response does not
freeze the standard's wording**. At 365 days the explanation was being deleted
while the score that relies on it lived on, so a 2024 result would eventually
refer to a rename with no surviving record.

It is now ten years, `ACTIVITYLOG_CLEAN_AFTER_DAYS`. Not a compliance figure,
simply longer than any inspection this database holds.

**If the table's size becomes the problem, a shorter window is the wrong
answer.** `activitylog:clean` takes a log name, and `log_name` here is the
subject's class basename (`moduleFor()` uses `class_basename`), so noisy modules
can be cleaned on their own while the register's provenance is kept. Which
records MoPS may discard is a policy decision, not a default.

### 2. A fact recorded only here is a fact with a deletion date

Writing the count of unanswered standards into the submission's log description
and nowhere else was exactly this mistake: pruned after a year, on an annual
inspection, and unreadable by anything, since nothing in this application reads
the log back. `ActivityLogsController` shows it to an administrator and that is
the whole of it. It is now
`service_area_submissions.unanswered_at_submission`, with the log line kept
beside it.

### What the sweep found, and what it cleared (on SDS, where this engine was built)

| Fact | Home |
| --- | --- |
| Officer's comment on submit / approve / reject | `submission_events.comment` |
| Required reason on a reopen | `submission_events.comment` |
| Required reason on a rejection | `submission_events.comment` |
| Final report comment | `submission_events.comment` |
| What a reinitialise added | `inspection_period_standards.created_at` / `created_by` |
| Unanswered standards at submission | **was log-only**, now a column |
| A standard's or question's rename | trail only, and that is right, but it must not be pruned first |

**Nothing in the application reads the trail**, which was verified rather than
assumed: `ActivityLogsController` and `Activity` are the only readers, and
`InspectionNotifier` and `LoggableTrait` only write. So pruning can never change
a score, a denominator or a worklist. What it can do is destroy the explanation
of why a stored figure says what it says, and that is what the retention change
protects.
