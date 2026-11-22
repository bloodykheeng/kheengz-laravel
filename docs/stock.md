# Stock

How many of each variant a shop has, where, and why it changed (workspace decisions D5, D33, D37; plan:
`docs/plan-data-model.md` §4).

## What is kept

| | Holds | Code |
| --- | --- | --- |
| Stock level | one variant at one location: **on hand**, and **held** for orders being paid or not yet sent | `StockLevel` |
| Stock movement | every change: its reason, the change to on hand and to held, the figures after it, a note, what it came from (an order, the other side of a move), who | `StockMovement`, `StockMovementReason` |
| Per variant | `track_stock` (counted or not) and `low_stock_at` (the line under which the shop is told) | `ProductVariant` |

**Available = on hand − held**, worked out, never stored. "In stock" on the storefront (step 4) is the sum
over the shop's locations. Quantities are DECIMAL(12,3) because weighed and measured units sell in parts
(1.5 kg); PHP adds and compares them as whole thousandths (`App\Support\Quantity`: 1.5 kg is 1500), so no
float ever drifts and no extension is needed.

## Changing stock: only `StockLedger`

Every change goes through `App\Services\StockLedger`, in one transaction:

1. the level row is made if it is the first time (`insertOrIgnore` on the unique key, so two first movements
   at the same moment cannot make two rows), then **locked** (`lockForUpdate`);
2. the new figures are checked: **nothing below zero, and never more held than on hand**; otherwise
   `InsufficientStock` with what is free;
3. the level is saved and a movement written with the figures after it.

So holding an order's items is one guarded step, and two buyers can never both get the last one.

| Who | Reason | On hand | Held | Method |
| --- | --- | --- | --- | --- |
| A shop's people | Received, Returned | + | | `receive`, `returned` |
| | Damaged | − | | `damaged` |
| | Counted (the shelf count becomes the figure; refused below what orders hold) | ± | | `count` |
| | Moved out / Moved in (two movements, each naming the other location) | − / + | | `move` |
| Orders (step 5) | Held, when an order is placed (D33) | | + | `hold` |
| | Released: payment failed or timed out, order cancelled | | − | `release` |
| | Sold online: a held item leaves the shop | − | − | `sell` |

Counter sales (step 7) join the table. A variant with **`track_stock` off** (made to order, or fresh produce
sold from what came in today) is never held or refused, and has no stock to record by hand.

**Movements are never edited or deleted.** A mistake is corrected by another movement (a recount, a return).
Because each movement keeps the figures after it, a stock card can be read line by line and checked against
a recount even after later changes. The only rows ever deleted are a **draft's** (never on sale, nothing on
the shelf) when the draft itself is deleted; the audit entry keeps the product.

What follows from keeping history:
- A variant with stock movements cannot be removed from its product: it is switched off (Offered) instead.
- A location that ever held stock cannot be deleted; what is left there is moved to another location.
- A draft with stock on the shelf cannot be deleted until it is counted to zero.

## Running low

When what is free of a variant (over all its locations) **falls to its `low_stock_at`**, and again when it
**runs out**, the vendor's Owners and the shop's Staff are told in the bell (`StockRunningLow`, linking the
product). Once per crossing: going back up and down again tells them again.

## API

| | | Who |
| --- | --- | --- |
| `GET /products/{id}/stock` | the stock card: every variant against every location (on hand, held, available) and the totals | whoever sees the product |
| `POST /products/{id}/stock` | record by hand: `variant_id`, `location_id`, `reason` (Received, Counted, Damaged, Returned, Moved), `quantity`, `to_location_id` for Moved, `note` | whoever runs the shop |
| `GET /products/{id}/stock/movements` | the history, newest first, filtered by variant, location, reason | whoever sees the product |
| `GET /shops/{id}/stock` | the shop's counted variants with what is free, `filter[state]=low` or `out`, search by product, SKU or barcode | whoever sees the shop |

Whole numbers are required for units without decimals ("Use whole pieces."). Every hand-recorded change is
also in the audit trail.

## Tests

`tests/Feature/Catalogue/StockTest.php`: each hand-recorded reason and its movement figures, refusals (more
than is free, half pieces, counting below what is held), hold, release and sell with the last item held only
once, uncounted variants, the low and out alerts once per crossing, the shop's low and empty lists, history
that keeps its variant, location and draft, and who may look and record.
