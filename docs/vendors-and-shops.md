# Vendors and shops

How a seller gets onto Kheengz and stays honest (workspace decisions D31, D35; plan:
`docs/plan-data-model.md` §1). A **vendor** is the person or business we verify once; a vendor runs
one or more **shops**, and each shop is approved on its own before it sells.

## Who is who

| | Holds | Code |
| --- | --- | --- |
| Vendor | name, kind (Individual, Company), the owner's NIN, URSB number for a company, contact, status | `Vendor`, `VendorStatus` |
| Member | an account's place in a vendor: **Owner** (every shop, the money, members, papers) or **Staff** (only the shops given) | `vendor_users` → `VendorUser`, `VendorMemberRole`; Staff's shops in `shop_user` |
| Document | ID card front and back; a company's registration; private files | `VendorDocument` on the `local` disk |
| Shop | name, address name (`homenest` → `homenest.kheengz.com`), type (D24), contact, status, its own commission and debt limits if set | `Shop`, `ShopStatus`, `ShopType` |
| Shop review | every step of a shop's history, with the reason and who | `ShopReview`, `ShopReviewDecision` |
| Location | where a shop keeps stock and ships from; one is the default | `Location` |
| Payout destination | a Mobile Money number or bank account the vendor is paid to; each shop picks one | `PayoutDestination`, `PayoutDestinationStatus` |
| Platform settings | commission, fees, debt limits, which ways of paying are on | `PlatformSettings::current()` |

Account roles stay Admin, Staff and Vendor. Every vendor's person has the account role **Vendor**
(permission `run shops`); Owner and Staff are places inside a vendor, never account roles (D26).

## Getting on

1. **Apply** (`POST /api/v1/vendor-applications`, public): the person's account (role Vendor, email to
   confirm with the 6-digit code) and a **Pending** vendor they own. The NIN is upper-cased and its
   spaces dropped before it is checked (`NationalIdNumber`: 14 characters, CM or CF first), and one NIN
   is one vendor.
2. **Papers** (`POST /vendors/{id}/documents`): photos or PDFs up to 10 MB. Another of the same type
   replaces the last. When a Pending vendor's papers become complete, the Admins are told
   (`VendorApplied`).
3. **Approval** (`POST /vendors/{id}/approve`, `manage vendors`): refused while papers are missing
   (`VendorApprovals::missingDocuments`). Reject and suspend need a reason the vendor reads. A rejected
   vendor corrects their details and resubmits.
4. **Shops** (`POST /vendors/{id}/shops`, the Owner): a **Draft** with its first location. The Owner
   sends it (`/submit`); the Admin approves, asks for changes, or rejects. A shop sells only when it is
   **Active and its vendor is Approved** (`Shop::isSelling`).

Every decision tells the people concerned in the bell and by email (`VendorDecided`, `ShopDecided`), and
by SMS to the vendor's or shop's phone.

## Statuses

```
Vendor:  Pending ──approve──▶ Approved ──suspend──▶ Suspended ──reinstate──▶ Approved
            │                                        (all its shops stop selling)
            └─reject──▶ Rejected ──resubmit──▶ Pending

Shop:    Draft ──submit──▶ Pending review ──approve──▶ Active ──suspend──▶ Suspended ──reinstate──▶ Active
                               │  │
                               │  └─request changes──▶ Changes requested ──submit──▶ Pending review
                               └─reject──▶ Rejected
         The vendor closes any shop that is not Suspended or already Closed.
```

Only these moves exist (`ShopReviews`, `VendorApprovals`). Each locks the row and checks the status it
starts from, so two reviewers pressing at once cannot both decide: the second gets a 422 on `status`
and the screen refetches (dashboard-patterns "A decision someone else already made").

## What keeps it honest

- **Each shop is reviewed**, and the version reviewed is the version approved: a shop cannot be edited
  while Pending review (`ShopPolicy::update`).
- **An approved shop keeps its name, address name and type**, so it cannot turn into a different shop
  after approval; the description, phone and email stay editable. A vendor's checked identity (name,
  kind, NIN, registration) is fixed the same way once approved. Support changes them.
- **Address names** (`ShopSlug`): 3 to 40 small letters, digits and single hyphens, never one of the
  platform's own (`Shop::RESERVED_SLUGS`: admin, api, pay, checkout…), never another shop's.
  `GET /shops/slug-availability?slug=` answers as the vendor types.
- **Closing is never a way round a suspension**: a Suspended shop cannot be closed by its vendor.
- **Debt is per shop** (D28, D31): only the shop that owes is hidden; the Admin sees every shop's figures
  together. (The ledger arrives with step 6.)
- **Papers are private**: stored under `vendor-documents/{vendor}/` on the `local` disk, served by
  `GET /vendor-documents/{id}/file` only to the vendor's Owner and to reviewers, with `no-store`. An
  approved vendor's papers cannot be deleted, only replaced: the approval rested on them.
- **A vendor always has an Owner**: the last one cannot be removed or made Staff.

## Payout destinations

Where a vendor's money goes (D12). Added by an Owner, never edited: a change is a new destination, and
each payout (step 6) will copy the details it used. `PayoutDestinations::add`:

- **Mobile Money**: the number's prefix decides the network (076 to 079 MTN; 070, 074, 075 Airtel), and it
  must be the network chosen. MarzPay has no name lookup for Mobile Money, so the name typed is compared.
- **Bank**: checked with MarzPay (`POST /bank-transfer/validate` through `App\Payments\MarzPay\MarzPayClient`).
  An account the bank does not know is refused at once; the name the bank holds (`checked_name`) is the one
  compared, not the one typed. If MarzPay cannot be reached or is not set up, the account is saved and waits
  for the Admin instead of stopping the vendor. The bank picker reads `GET /payout-banks` (cached a day).
- **Verified** when that name matches the vendor or one of its Owners (`NameMatch`: case, punctuation, word
  order and "Ltd", "Limited" do not count; every word of the shorter name in the longer, at least two words in
  common). Otherwise **Awaiting review**: the Admins are told and approve or reject it with a reason.
- **Every new destination is SMSed to the vendor's phone** ("If this was not you, call support at once"), so a
  stolen sign-in cannot quietly send the money elsewhere.
- **Shops**: only an Owner chooses where a shop is paid, never a refused destination. A shop needs a
  destination to be sent for review and a Verified one to be approved. A destination a shop is paid to cannot
  be removed until the shop picks another (409).

## Who may do what

| | Admin | Staff (platform) | Vendor Owner | Vendor Staff |
| --- | --- | --- | --- | --- |
| See vendors and shops | all | all | their vendor, its shops | their vendor, the shops given |
| Decide on vendors and shops | yes | when given `manage vendors` | no | no |
| Edit the vendor, papers, members | no | no | yes | no |
| Open, send for review, close a shop | no | no | yes | no |
| Edit a shop and its locations | no | no | yes | the shops given |
| Add or remove payout destinations, pick a shop's | no | no | yes | no |
| Approve or reject a waiting payout destination | yes | when given `manage vendors` | no | no |
| Platform settings | yes (`manage settings`) | no | no | no |

The route's `can:` names the policy (`VendorPolicy`, `ShopPolicy`); the policy decides whose.

## Tests

`tests/Feature/Vendors/`: the application, papers and approval, shops through review, one shop
suspended while the others sell, Staff limited to their shops, one vendor kept out of another's,
members and the last Owner, locations and the default, platform settings, payout destinations
(networks, MarzPay's bank check faked at the HTTP level, name matching, the Admin's decision, the Owner's
choice for a shop).
