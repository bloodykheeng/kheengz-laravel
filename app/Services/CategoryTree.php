<?php

namespace App\Services;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The rules of the category tree (D32), shared by the category screen and by answering a vendor's
 * request: a name once among its siblings, never inside itself, at most Category::MAX_DEPTH deep,
 * and an address name unique across the tree. docs/catalogue.md.
 */
class CategoryTree
{
    /**
     * @param  array{name: string, parent_id?: string|null, icon?: string|null, position?: int, is_active?: bool}  $data
     */
    public function create(array $data, User $by): Category
    {
        $parentId = $data['parent_id'] ?? null;
        $this->assertNameFree($data['name'], $parentId, null);
        $this->assertPlacement(null, $parentId);

        return Category::query()->create([
            ...$data,
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug($data['name'], $parentId, null),
            'position' => $data['position'] ?? (int) Category::query()->where('parent_id', $parentId)->max('position') + 1,
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $by->id,
            'updated_by' => $by->id,
        ]);
    }

    public function assertNameFree(string $name, ?string $parentId, ?string $ignore): void
    {
        $taken = Category::query()
            ->where(fn ($q) => $parentId === null ? $q->whereNull('parent_id') : $q->where('parent_id', $parentId))
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => ['There is already a category with that name here.']]);
        }
    }

    /** `$category` (null for a new one) may sit under `$parentId`: not inside itself, not too deep. */
    public function assertPlacement(?Category $category, ?string $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = Category::query()->findOrFail($parentId);
        if ($category && $category->isSelfOrAncestorOf($parent)) {
            throw ValidationException::withMessages(['parent_id' => ['A category cannot go inside itself or one of its own.']]);
        }
        $height = $category ? $this->subtreeHeight($category) : 1;
        if ($parent->depth() + $height > Category::MAX_DEPTH) {
            throw ValidationException::withMessages(['parent_id' => ['That would make the tree deeper than '.Category::MAX_DEPTH.' levels. Choose a higher place.']]);
        }
    }

    /** basins; or kitchen-basins when another branch has basins; then -2. */
    public function uniqueSlug(string $name, ?string $parentId, ?string $ignore): string
    {
        $taken = fn (string $slug) => Category::query()->where('slug', $slug)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists();
        $slug = Str::slug($name) ?: 'category';
        if ($taken($slug) && $parentId) {
            $slug = Str::slug(Category::query()->whereKey($parentId)->value('name').' '.$name);
        }
        for ($base = $slug, $n = 2; $taken($slug); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    /** Levels from this category down to its deepest descendant, itself included. */
    private function subtreeHeight(Category $category): int
    {
        $height = 1;
        $level = collect([$category->id]);
        while (($level = Category::query()->whereIn('parent_id', $level)->pluck('id'))->isNotEmpty() && $height <= Category::MAX_DEPTH) {
            $height++;
        }

        return $height;
    }
}
