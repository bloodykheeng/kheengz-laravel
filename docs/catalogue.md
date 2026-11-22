# Catalogue: categories, sections, products

How products are described, grouped and priced (workspace decisions D17, D32, D36; plan:
`docs/plan-data-model.md` §3). Two groupings live side by side: the Admin's **marketplace categories**,
for finding and filtering across shops, and each shop's own **sections**, for its menu.

## What is kept

| | Holds | Code |
| --- | --- | --- |
| Category | name, parent, address name (`basins`), icon, position, on or off | `Category`; tree rules in `CategoryTree` |
| Attribute | what filters are made of: Colour (a list), Capacity (a number in L), Brand (text) | `ProductAttribute`, `AttributeInputType`, `ProductAttributeOption` |
| Category attribute | which attributes a category's products are described by, in order, required or not | `category_product_attribute` |
| Category request | a shop asking for a missing category, and the Admin's answer | `CategoryRequest`, `CategoryRequestStatus` |
| Unit | piece, dozen, kg, metre; whether it takes decimals | `Unit`; per shop type `ShopType::suggestedUnits()` |
| Shop section | a shop's own group ("Back to school"), ordered, with a photo | `ShopSection` |
| Product | shop, one category, sections, name, address name, description, status, collected only | `Product`, `ProductStatus` |
| Product detail | its value for one of its category's attributes | `ProductAttributeValue` |
| Option | the vendor's own (Size, Colour, Scent), up to 3, with values | `ProductOption`, `ProductOptionValue` |
| Variant | what the buyer picks: its option values, SKU, barcode, unit, price, was-price, weight | `ProductVariant` |
| Price break | from this quantity, each costs less (wholesale) | `PriceBreak` |
| Photo | WebP at 1600 px and 480 px, in order, maybe tied to a variant | `ProductPhoto`, `ProductImage` |

Video and TikTok links (D20) come with step 7; stock per variant per location with step 3.

## Categories (the Admin's, `manage catalogue`)

- **One tree, at most four levels** (Household › Kitchen › Basins › Round basins). A name appears once
  among its siblings, ignoring case; the address name is unique across the whole tree (`accessories`, or
  `phones-accessories` when another branch has it). A category never moves inside itself.
- **Read whole, not paged** (`GET /categories`): it is the menu, a few hundred rows, and a picker needs
  all of it. It comes flat, each straight after its parent, with `depth` and `path`; `?active=1` leaves
  out categories that are off and everything under them.
- **Attributes flow down.** A category's products are described by its own attributes and its parents'
  (`Category::effectiveAttributes()`, in `GET /categories/{id}` as `effective_attributes`). Giving a
  category an attribute it already inherits is refused.
- **Off, not deleted.** Deleting works only for an empty category nothing points at; otherwise it is
  turned off: vendors can no longer choose it, and products in it keep it until moved (an Active product
  in a category that is off cannot be saved until it is moved).

### Attributes and units

- An attribute's **type** (List, Number, Text) and a **choice** products use stay; a choice is renamed in
  place (by id), so products keep it. An attribute products use cannot be deleted; one only on
  categories comes off them.
- A unit's **decimals** stay once products are sold by it; such a unit is turned off, not deleted.
  `GET /units?shop_type=…` returns that type's units first, marked `suggested`.

### Category requests

From the product form a shop asks for a missing category (`POST /shops/{id}/category-requests`). A name
that already exists is pointed to instead, and the same shop cannot ask twice while one is Pending. The
Admins are told in the bell. The Admin either **adds** it (a new category through `CategoryTree`, or one
that already fits, `category_id`) or **declines** with a reason; whoever asked is told in the bell and by
email. A request is answered once: a second answer gets a 422 on `status`.

## Shop sections

Named and ordered by the vendor (`PUT /shops/{id}/sections/order` with every id), at most 30 per shop, a
name once per shop ignoring case. A section's photo is kept at 480 px. Removing a section only takes its
products out of it.

## Products

**Saved whole.** The product form sends everything in one request (`POST /shops/{id}/products`,
`PUT /products/{id}`) and `ProductCatalogue::save()` writes it in one transaction:

```json
{
  "name": "Round Basin", "category_id": "…", "description": "…", "collect_only": false,
  "section_ids": ["…"],
  "attributes": [{ "attribute_id": "…", "number": 20 }, { "attribute_id": "…", "option_id": "…" }],
  "options": [{ "name": "Colour", "values": ["Red", "Blue"] }, { "name": "Size", "values": ["10 L", "20 L"] }],
  "variants": [
    { "id": "…", "option_values": ["Red", "20 L"], "sku": "BAS-R20", "unit_id": "…", "price": 6000,
      "compare_at_price": 7000, "weight_grams": 900, "is_active": true,
      "price_breaks": [{ "min_quantity": 12, "unit_price": 5500 }] }
  ]
}
```

- **Ids live on.** Options and values are matched to what is there by name (ignoring case), variants by
  `id` or else by their values, so editing a product never changes the variants it keeps (stock and
  orders point at them). Variants no longer sent are removed, then the options and values only they used.
- **An option that matches an attribute feeds its filter.** An option named like one of the category's
  List attributes ("colour" and Colour) is linked to it, and each value that matches a choice counts as
  that choice; a vendor is still free to add options the category does not have (D32).
- **Checks** (422 on the field): one value of each option per variant, no two variants alike, a product
  with no options has one variant, a was-price above the price, price breaks from 2 up that each cost
  less than the last and whole for units without decimals, SKUs unique within the shop (other shops may
  reuse them), details only from the category's attributes.
- **Statuses.** A product starts as a **Draft**. `POST /products/{id}/publish` puts it **Active** when it
  has a photo, an offered variant, its category's required details, and a category that is on
  (`problemsForSale()`, each problem on its field). An Active product stays fit for sale: a save that would
  break that is refused whole. `POST /products/{id}/hide` takes it off sale (**Hidden**). Only a Draft that
  was never on sale can be deleted; anything else is hidden, because orders may point at it. Buyers see an
  Active product only while its shop is selling (step 4).
- **Taken down by Kheengz** (D35, D36). `POST /products/{id}/block` with a reason (`manage vendors`) hides
  it at once and tells the shop's Owners and Staff in the bell and by email (`ProductBlocked`). They can
  still edit it, but publishing is refused until `POST /products/{id}/unblock`; then they put it back on
  sale themselves.
- **Who.** Anyone who runs the shop, in any shop status but Rejected and Closed (`ShopStatus::
  allowsCatalogueEdits`): also while the shop is reviewed (the reviewer can see what it will sell) and
  while suspended (fixing the products may be what the Admin asked). Reviewers (`view vendors`) can look.

## Photos

`POST /products/{id}/photos` takes files (`photos[]`, JPG, PNG or WebP up to 15 MB) or one pasted `url`,
at most 10 per product. Every photo goes through `ProductImage`:

- turned upright from the camera's EXIF orientation, then **every byte of metadata dropped** (a phone's
  EXIF carries where the photo was taken, often the vendor's home);
- refused when smaller than 400 px on a side or over 30 megapixels (the memory guard);
- kept as **WebP**, up to 1600 px and a 480 px thumbnail, on the `public` disk under
  `shops/{shop}/products/{product}/` (run `php artisan storage:link` once per machine).

A pasted link goes through `ImageFromLink::download()`: http(s) only, every address the host (and each
redirect) resolves to must be public, the connection is pinned to the address checked (no DNS
rebinding), three redirects, 20 seconds, 20 MB, and the bytes must be an image. The link is kept as
`source_url`. `PUT /products/{id}/photos/order` sets the order (the first is the cover);
`PUT /products/{id}/photos/{photo}` ties a photo to a variant. The last photo of an Active product cannot
be removed.

## Starting catalogue

`CatalogueSeeder` (part of `db:seed`) adds 17 units, 12 attributes and a 60-category tree covering the
shop types, each part only into an empty table, so running it again never undoes the Admin's changes.

## Tests

`tests/Feature/Catalogue/*`: the tree's rules and who may change it, attributes and units that products
use, requests answered once and told, sections, the whole product save (ids surviving an edit, every
check), publish and hide, photos at two sizes, link import and private addresses refused.
