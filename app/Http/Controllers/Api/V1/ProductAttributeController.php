<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AttributeInputType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductAttributeResource;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeOption;
use App\Support\PerPage;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Product attributes (D32): what filters are made of. The Admin defines them once (Colour with its
 * choices, Capacity in L) and gives them to categories. A choice or a type that products already use
 * cannot be taken away from under them. docs/catalogue.md.
 */
#[Group('Catalogue', 'Categories, attributes, units and category requests.', weight: 5)]
class ProductAttributeController extends Controller
{
    use LoggableTrait;

    public function index(Request $request): AnonymousResourceCollection
    {
        $attributes = QueryBuilder::for(ProductAttribute::query())
            ->allowedFilters(
                AllowedFilter::exact('input_type'),
                AllowedFilter::callback('search', fn (Builder $q, $value) => $q->where('name', 'like', "%{$value}%")),
            )
            ->allowedSorts('name', 'created_at')
            ->defaultSort('name')
            ->with('options')
            ->withCount('categories')
            ->paginate(PerPage::from($request))
            ->appends($request->query());

        return ProductAttributeResource::collection($attributes);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request, null);
        $by = $request->user()->id;

        $attribute = DB::transaction(function () use ($validated, $by) {
            $attribute = ProductAttribute::query()->create([
                ...collect($validated)->except('options')->all(),
                'created_by' => $by,
                'updated_by' => $by,
            ]);
            $this->syncOptions($attribute, $validated['options'] ?? []);

            return $attribute;
        });

        $this->logActivity('attribute_created', "Attribute {$attribute->name} created.", ['snapshot' => $this->buildModelSnapshot($attribute)], $attribute);

        return response()->json(['message' => "{$attribute->name} added.", 'data' => new ProductAttributeResource($attribute->load('options'))], 201);
    }

    public function update(Request $request, ProductAttribute $attribute): JsonResponse
    {
        $validated = $this->validated($request, $attribute);

        if (isset($validated['input_type']) && $validated['input_type'] !== $attribute->input_type->value && $attribute->isInUse()) {
            throw ValidationException::withMessages(['input_type' => ['Products already use this attribute, so how it is filled in stays.']]);
        }

        $before = $this->captureOriginal($attribute);
        DB::transaction(function () use ($attribute, $validated, $request) {
            $attribute->update([...collect($validated)->except('options')->all(), 'updated_by' => $request->user()->id]);
            if (array_key_exists('options', $validated)) {
                $this->syncOptions($attribute, $validated['options']);
            }
        });
        $this->logActivity('attribute_updated', "Attribute {$attribute->name} updated.", ['changes' => $this->buildChanges($attribute, $before)], $attribute);

        return response()->json(['message' => 'Saved.', 'data' => new ProductAttributeResource($attribute->fresh('options'))]);
    }

    /** Never one products use; one that is only on categories comes off them. */
    public function destroy(ProductAttribute $attribute): JsonResponse
    {
        abort_if($attribute->isInUse(), 422, 'Products are described by this attribute, so it stays. Take it off the categories instead.');

        $snapshot = $this->buildModelSnapshot($attribute);
        DB::transaction(function () use ($attribute) {
            $attribute->categories()->detach();
            $attribute->options()->delete();
            $attribute->delete();
        });
        $this->logActivity('attribute_deleted', "Attribute {$attribute->name} deleted.", ['snapshot' => $snapshot], $attribute);

        return response()->json(['message' => "{$attribute->name} deleted."]);
    }

    /**
     * The choices, in order. Kept ones (by id) are renamed in place, so products keep them; a removed
     * one goes only if nothing uses it.
     *
     * @param  list<array{id?: string|null, value: string}>  $options
     */
    private function syncOptions(ProductAttribute $attribute, array $options): void
    {
        if ($attribute->input_type !== AttributeInputType::List) {
            // A type products use cannot change (update() refuses), so these choices are unused.
            $attribute->options()->delete();

            return;
        }

        $existing = $attribute->options()->get()->keyBy('id');
        $byValue = $existing->keyBy(fn (ProductAttributeOption $o) => mb_strtolower($o->value));
        $kept = [];
        foreach (array_values($options) as $position => $row) {
            // By id when given (a rename); otherwise a choice already there under that name is the same one.
            $option = ! empty($row['id']) ? $existing[$row['id']] ?? null : $byValue[mb_strtolower(trim($row['value']))] ?? null;
            if (! empty($row['id']) && $option === null) {
                throw ValidationException::withMessages(["options.{$position}.id" => ['That choice is not part of this attribute.']]);
            }
            $option ??= new ProductAttributeOption(['product_attribute_id' => $attribute->id]);
            $option->fill(['value' => trim($row['value']), 'position' => $position])->save();
            $kept[] = $option->id;
        }

        foreach ($existing->except($kept) as $removed) {
            if ($removed->isInUse()) {
                throw ValidationException::withMessages(['options' => ["Products use \"{$removed->value}\", so it stays."]]);
            }
            $removed->delete();
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ProductAttribute $attribute): array
    {
        $required = $attribute === null ? 'required' : 'sometimes';
        $type = $request->input('input_type', $attribute?->input_type->value);

        return $request->validate([
            'name' => [$required, 'string', 'min:2', 'max:60', Rule::unique('product_attributes', 'name')->ignore($attribute?->id)],
            'input_type' => [$required, Rule::enum(AttributeInputType::class)],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'hint' => ['sometimes', 'nullable', 'string', 'max:160'],
            'options' => [$type === AttributeInputType::List->value && $attribute === null ? 'required' : 'sometimes', 'array', 'max:100'],
            'options.*.id' => ['nullable', 'string'],
            'options.*.value' => ['required', 'string', 'max:60', 'distinct:ignore_case'],
        ], [
            'name.unique' => 'There is already an attribute with that name.',
            'options.required' => 'Add the choices people pick from.',
            'options.*.value.distinct' => 'That choice is listed twice.',
        ]);
    }
}
