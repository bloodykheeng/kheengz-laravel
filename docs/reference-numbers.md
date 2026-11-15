# Reference Numbers, Account Numbers and Serials

> Every number a person reads or types (an application number, an account number, a
> statement serial) comes from one helper, [`App\Support\SerialCounter::take()`](../app/Support/SerialCounter.php).
> It keeps **the last counter handed out** per series in `serial_counters` and raises it under a
> row lock, so a number is never handed out twice, not even after its record is deleted. Its
> month is the **business** month (Kampala), never UTC's. It never works the next number out
> from id order, a row count, or "the highest still in the table". This doc lists the series,
> explains why those shortcuts fail, and gives the checklist for adding a new series.

Related: [ulid-primary-keys.md](ulid-primary-keys.md) (why ids are ULIDs and what their
order means), [transactions-and-locking.md](transactions-and-locking.md) §4 (races).

---

## 1. The series

Kheengz's numbers arrive with their modules (Phase 1). Each is a new series in `serial_counters`:

| Number | Example | Column | Assigned | Made by |
| --- | --- | --- | --- | --- |
| Order | `KZ1120261` | `orders.order_number` | when the buyer places it | `SerialCounter::take()` |
| Payout | `PO1120261` | `payouts.reference` | when the payout is created | `SerialCounter::take()` |
| Counter sale receipt | `CS1120261` | `counter_sales.receipt_number` | when the sale is saved | `SerialCounter::take()` |

The examples below the table come from Alpha, where this rule was fixed on 12 Nov 2026 (the
NICE project found the bug): investment accounts, transactions and issued documents are Alpha's
tables, kept because they show exactly how the shortcuts failed.

## 2. How the next number is found

```php
$stem = $prefix.SerialCounter::monthStem();                              // PREFIX + MMYYYY, Kampala month
$number = SerialCounter::take(Model::query(), 'column', $stem);          // unpadded counter
$serial = SerialCounter::take(IssuedDocument::query(), 'serial', $stem, 4); // zero-padded to 4
```

`serial_counters` holds one row per series: `table_name`, `column_name`, `stem` (together the
key: a product code is free text, so an account stem can spell the same as another series')
and `last_value`. `take()`, in a transaction:

1. Locks the series' row (`SELECT ... FOR UPDATE`). The first time a series is used, it seeds
   the row from the highest number already in the table (`highestUsed()`), then locks it.
2. Adds one, stepping past any number already on a record (one written by hand or before the
   counter existed).
3. Writes `last_value` back.

Inside a caller's transaction (an approval, a posting) the lock holds until that transaction
commits, and a rollback gives the number back. Outside one, the counter commits on its own: a
save that then fails leaves a gap, never a duplicate.

`highestUsed()` finds the highest value under the stem:

- It orders by `LENGTH(column) DESC, column DESC`. Within one series the stem has a fixed
  width, so a longer value holds a bigger counter, and values of equal length compare as
  strings. This works for unpadded counters (`…9` < `…10`) and padded ones
  (`…9999` < `…10000`).
- It matches the stem with `LIKE … ESCAPE '!'` and escapes `_` and `%`. Product codes are
  free text, so without the escape, code `A_F` would read the numbers of `AXF`.

## 3. Never by id, never by count, never "highest + 1"

These shortcuts all look right in a demo and all fail once real users are on the system.

### Id order is creation order, not number order

A ULID begins with the millisecond the row was **created**. So `ORDER BY id DESC` means
"the newest row", which is not the same as "the row with the highest number":

- **A number assigned after creation.** Account numbers are issued at approval, and
  applications are not approved in the order they were started. Once an older application
  is approved after a newer one, the older one holds the higher number, and "the newest
  account's number + 1" is a number already in use.
- **Rows created in the same millisecond** on different queue workers or requests are not
  ordered by id at all.

### A count goes down when a row is deleted

`count() + 1` is only right while no row is ever deleted. Issued documents can be
bulk-deleted. Delete statement `0002` out of `0001`-`0003`, and the count is 2, so the next
statement gets `0003`, which already exists. From then on, every statement of that type
for that month fails on the unique index.

### "Highest still in the table + 1" reissues a deleted newest number

This helper worked that way until 12 Nov 2026. It is right after a **middle** record is
deleted, and wrong after the **newest** one is: create `TX1120265`, delete it, and the next
transaction was `TX1120265` again. The unique index does not help, because the old row is gone.

Only a record that was never approved can be deleted (the `Approvable` delete guard refuses
anything approved or pending, transactions included), but its number has often left already:

- a draft that was submitted and rejected was named in the approval-request emails and
  notifications ("TX1120263 · Deposit ... awaiting your approval"), now someone else's number;
- a deleted or regenerated issued document's serial is printed on the PDF emailed to the investor;
- exports and the audit trail ("TX1120265 deleted", then "TX1120265 created" for a different
  record): a delete followed by a new entry under the same number reads like an edit of the original.

The rule: **a number, once anyone has seen it, means one record forever.** A deleted number
stays a gap, and the audit trail explains it (who deleted it and when). That is what an auditor
or the CMA expects of a fund's records. It applies to every series, issued documents included:
a regenerated or re-issued statement takes a new serial.

Found by the NICE project while copying this pattern (5 Nov 2026,
`nice/notes-for-alpha/reference-numbers-review.md`), fixed here 12 Nov 2026.
Tests: `SerialNumbersTest` (each fails if `highestUsed() + 1` is put back).

### The month is the business month

`now()` is UTC (`config/app.php`), so `now()->format('mY')` gave anything made between 00:00
and 02:59 Kampala time on the 1st the **previous** month's stem, while every report counted it
in the new month. `SerialCounter::monthStem()` reads `BusinessDate::today()`. Document serials
already used the Kampala date (`BusinessDate::dayOf`, the statement's period). Test:
`SerialNumbersTest` ("a number made just after midnight on the 1st takes the new month", clock
at 22:00 UTC on 30 Nov).

### The incident that found this (test server, 2026-10-20)

1. Maria's application (started 11:50) was approved **after** Grace's (started 11:59).
   Grace got `AIF1020266`, Maria `AIF1020267`.
2. Approving a third application read "the newest numbered account": Grace's, `…266`.
   It computed `…267`, which was Maria's. The update hit the unique index and the
   approval returned a 500.
3. The checker's "Approved" decision had already been saved **before** the write that
   failed, so the account showed its final step as approved while it stayed
   `Pending Approval`.

Fixed together:

- the generators now use `SerialCounter`;
- `ApprovalRequestController::submitDecision` writes the decision in the same transaction
  as its effect, so a failed approval leaves no decision behind.

Tests: `AccountApprovalNumberingTest`, and `IssuedDocumentTest` ("numbers the next
statement past a deleted one").

**Finding requests stuck this way:** a pending request with an `Approved` outcome on its
current step. That outcome never took effect and no audit entry was written for it.

```php
DB::table('approval_outcomes as o')
    ->join('approval_requests as r', 'r.id', '=', 'o.approval_request_id')
    ->where('r.status', 'Pending')->where('o.decision', 'Approved')
    ->whereColumn('o.step_number', 'r.current_step_number')
    ->select('o.id', 'r.approvable_type', 'r.approvable_id', 'o.created_at')->get();
```

Delete the outcome ids that query lists. The approver then decides again.

## 4. What id order IS fine for

The rule is about **meaning**: use id order only when "created earlier" is what you mean.

| Use | OK? | Why |
| --- | --- | --- |
| Tiebreak after a real sort (`->orderBy('transaction_date')->orderBy('id')`) | Yes | Keeps pages stable. The real column decides the order. |
| "Most recent decision / message" (`latestRejectionReason`, support chat paging) | Yes | Creation order is the meaning. A ULID is even finer than `created_at`, which stops at the second. |
| Ledger or statement order | Only as a tiebreak | Order by the business date first ([timezone-handling.md](timezone-handling.md)). |
| Next number in a series | **No** | Use `SerialCounter`. |
| Anything "issued", "approved" or "effective" after creation | **No** | Order by that event's own column. |

## 5. Races

The row lock makes two writers at the same moment wait for each other, so they take different
numbers. On SQLite (the tests) `lockForUpdate` does nothing, but SQLite writes one at a time.
The unique index on every column stays the last guard:

- **`HasReferenceNumber`** still catches a duplicate on insert and regenerates, up to five
  times (transactions-and-locking.md §4): a number typed in by someone else in between.
- **Account numbers and document serials** do not retry. A clash fails that one request
  cleanly: an approval rolls back with no decision recorded, and the user tries again.

## 6. Checklist for a new numbered series

- [ ] A string column with a **unique** index on the create migration.
- [ ] A fixed-width stem per series (prefix + period). The counter is everything after it.
- [ ] Next number from `SerialCounter::take()`, never `orderBy('id')`, never `count()`, never
      "highest + 1". The month from `SerialCounter::monthStem()` (or a business date), never `now()`.
- [ ] Assigned on create? Use `HasReferenceNumber`, and assign it explicitly in any
      `WithoutModelEvents` seeder (ulid-primary-keys.md §5).
- [ ] Assigned later (at approval, at issue)? Assign it inside the transaction that does
      the rest of that write.
- [ ] A test where rows are numbered **out of creation order**, one where the **newest** row is
      **deleted** (its number must not come back), and one at 22:00 UTC on the last day of a month.
