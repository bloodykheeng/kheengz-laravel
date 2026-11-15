# kheengz-laravel docs

One topic per file, updated in the same change as the code it describes. The workspace's
`docs/decisions.md` says what was settled and why; these say how the code does it.

| Doc | What it covers | Came from |
| --- | --- | --- |
| [environment-setup.md](environment-setup.md) | Every `.env` value that needs an outside account | Kheengz |
| [visitor-origin.md](visitor-origin.md) | Country, region, IP and device on the audit trail and on orders | Kheengz |
| [rate-limits.md](rate-limits.md) | Buyer limits per person, not per IP | GiftNest, rewritten |
| [reference-numbers.md](reference-numbers.md) | Order and payout numbers from `SerialCounter`, never reused | Alpha |
| [audit-trail.md](audit-trail.md) | `LoggableTrait`, `LogActivityJob`, what an entry answers | GiftNest (built on SDS) |
| [snapshot-schema.md](snapshot-schema.md) | `snapshotSchema()` on every model, frozen values in the trail | GiftNest (from Alpha) |
| [timezone-handling.md](timezone-handling.md) | UTC stored, Kampala days, `BusinessDate` | GiftNest (from Alpha, NICE) |
| [ulid-primary-keys.md](ulid-primary-keys.md) | ULID keys and what their order means | GiftNest |
| [foreign-key-conventions.md](foreign-key-conventions.md) | `restrict` by default, deleting through models | GiftNest (from SDS) |
| [migrations-and-table-naming.md](migrations-and-table-naming.md) | Table and pivot names, spaced timestamps | GiftNest (from SDS) |
| [naming-and-casing.md](naming-and-casing.md) | snake_case on the wire, Title Case statuses | GiftNest |
| [list-sorting-3-state.md](list-sorting-3-state.md) | Asc, desc, off on every list | GiftNest (from SDS) |
| [n-plus-one-guard.md](n-plus-one-guard.md) | `preventLazyLoading()` and how to fix a violation | GiftNest |
| [otp-and-short-lived-secrets.md](otp-and-short-lived-secrets.md) | Codes in the cache, constant-time checks | GiftNest |
| [static-analysis.md](static-analysis.md) | Larastan level 5 (here with no baseline) | GiftNest |

Copied docs keep their original examples (SDS districts, Alpha investment accounts) where those show
why a rule exists; Kheengz examples replace them as modules land. GiftNest's deletion policy, realtime,
public endpoints and photos-from-links docs were not copied: they describe events and gifts, and each
is written fresh with the Kheengz module it belongs to.
