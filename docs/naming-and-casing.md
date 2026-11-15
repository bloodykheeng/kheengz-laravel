# Naming and casing across the wire

**Status:** convention · binding on both repos.

One rule, and it is not "pick a casing and be consistent":

> **Every key the API sends or receives is `snake_case`. Every PHP method and
> every TypeScript local keeps its own language's casing.**

The wire is not a language. It is Laravel's serialisation format, and Laravel
already decided this for us.

---

## 1. Why, with the evidence

`Model::$snakeAttributes` is `true` by default, so Eloquent snake_cases relation
keys when it serialises. Run it:

```php
Parish::with(['subCounty', 'createdBy'])->first()->toArray();
// => [... 'sub_county' => [...], 'created_by' => [...]]
```

That is what a controller returning a **model directly** emits. If a resource
returns `subCounty`, the two disagree, and which shape the frontend gets depends
on whether that endpoint happened to be written with a resource. That is the bug
this rule exists to prevent, and it is how the inconsistency was found.

There is a sharper edge worth knowing. A loaded `createdBy` relation
**overwrites the `created_by` column** in Eloquent's own array:

```php
$parish->created_by;                       // '01K3...'  the ULID
$parish->load('createdBy')->toArray()['created_by'];  // the whole User array
```

No resource here exposes both, so nothing collides today. Check it if you ever
add a resource that returns a `*_by` or `*_id` column *and* its relation.

---

## 2. Where each casing applies

| Thing | Casing | Example |
| --- | --- | --- |
| Resource output key | `snake_case` | `'sub_county' => ...` |
| `include=` value the client sends | `snake_case` | `?include=sub_county,created_by` |
| Request body field | `snake_case` | `sub_county_id`, `service_area_ids` |
| Filter / sort params | `snake_case` | `filter[region_id]`, `sort=created_at` |
| **Eloquent relation method** | `camelCase` | `public function subCounty()` |
| `whenLoaded()` / `->load()` / `with()` | `camelCase` | `$this->whenLoaded('subCounty')` |
| PHP variable | `camelCase` | `$serviceAreaIds` |
| TS interface field mirroring a record | `snake_case` | `sub_county?: RelatedRecord` |
| TS form schema field | `snake_case` | `service_areas: z.array(relation)` |
| TS local, import, type name | `camelCase` / `PascalCase` | `const serviceAreas`, `ServiceAreaFields` |
| React component prop | `camelCase` | `siblingScope`, `presetServiceArea` |
| URL query param | `camelCase` | `?serviceAreaId=...`, `?unitId=...` |

The last two are the ones people get wrong in the other direction. A component
prop and a URL param are **not** wire keys: nothing serialises them, they are
frontend-internal, and they stay camelCase.

### The one exemption

`snapshotSchema()` keys stay camelCase (`'serviceAreas' => [...]` on
`AdministrativeUnit`). They are audit-log property names, not wire keys: they are
written into `activity_log.properties` and read back with the `label` stored
alongside them. Renaming one would leave every historical entry keyed the old way
and every new entry keyed the new way, for no gain — nothing sends or receives
them as an API field.

---

## 3. Adding a relation: the checklist

`subCounty` on parishes, end to end:

**Laravel**

1. Model: `public function subCounty(): BelongsTo` — camelCase, it is a method.
2. Resource: `'sub_county' => new SubCountyResource($this->whenLoaded('subCounty'))`
   — snake key, camelCase argument.
3. Controller: `AllowedInclude::relationship('sub_county', 'subCounty')` — the
   first argument is what the client sends, the second is the relation method.
   A plain string `allowedIncludes('subCounty')` would force the client to send
   camelCase, which is the mistake.
4. `#[QueryParameter('include', '... sub_county ...')]` so the docs match.
5. Single-word relations (`region`, `vote`, `parish`) need no mapping: a plain
   string is already correct.

**Next**

6. `services/<domain>`: `sub_county?: RelatedRecord | null;`
7. `include: "sub_county,created_by,updated_by"`.
8. Form schema field, if the form holds it: `sub_county: relation.nullish()`.
9. Table column `field: "sub_county"` — it is the export's path into the row.

---

## 4. The two traps

**`include=` lists are strings, and TypeScript cannot see inside them.**

```ts
include: "subCounty,createdBy,updatedBy"   // compiles perfectly, loads nothing
```

A rename that misses these leaves the app type-clean and silently relation-less:
every nested key comes back absent, so the columns render blank. This is exactly
what happened during the rename, in 22 files at once. There is no compiler for
this, so grep for it (§5).

**A regex rename cannot tell a wire key from a prop.**

`serviceAreas?: {...}` in a `Props` interface and `serviceAreas?: {...}` in a
record type look identical. Renaming the first breaks the component while the
second is what you meant. Check every `*Props` interface a sweep touched.

---

## 5. Checking it

```bash
# Laravel: a resource key that is not snake_case
grep -rn "'[a-z]*[A-Z][A-Za-z]*' =>" app/Http/Resources/

# Laravel: a camelCase name exposed to the client. Only the FIRST argument is
# the wire name; the second is the Eloquent method and is meant to be camelCase.
grep -rnE "AllowedInclude::relationship\('[a-z]+[A-Z]" app/Http/Controllers/

# Laravel: a bare camelCase string still sitting in an allowedIncludes list
awk '/allowedIncludes\(/,/\)/' $(grep -rl "allowedIncludes(" app/Http/Controllers/ -r) \
  | grep -E "^ +'[a-z]+[A-Z]"

# Next: a camelCase relation inside an include list
grep -rn 'include[:=] *["`][^"`]*[a-z][A-Z]' app services

# Next: camelCase property access on a record
grep -rn '\.\(createdBy\|updatedBy\|subCounty\|serviceAreas\|administrativeUnit\|financialYear\)\b' app components services
```

All five should return nothing.

Do **not** widen these to any camelCase string in a controller: `->load([...])`,
`with([...])` and `whenLoaded()` all take Eloquent method names and are correctly
camelCase. An earlier draft of this file did exactly that and flagged
`AuthCheckController::profile`, which was fine. `ResponseShapeTest` pins the response side:

```php
expect($response->json('data.administrative_unit.name'))->toBe('Kampala District')
    ->and($response->json('data'))->not->toHaveKey('administrativeUnit');
```

That test is the reason a future rename cannot half-land on the backend. Nothing
equivalent guards the frontend, so §5's greps are the substitute.
