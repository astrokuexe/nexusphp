<?php

namespace App\Models;


use App\Models\Traits\NexusActivityLogTrait;

class Category extends NexusModel
{
    use NexusActivityLogTrait;

    protected $table = 'categories';

    protected $fillable = ['mode', 'name', 'class_name', 'image', 'sort_index', 'icon_id', 'parent_id'];

    public static function getLabelName()
    {
        return nexus_trans('searchbox.category_label');
    }

    public function icon(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Icon::class, 'icon_id');
    }

    public function search_box()
    {
        return $this->belongsTo(SearchBox::class, 'mode', 'id');
    }

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public static function listMenuGroups(int $mode): \Illuminate\Database\Eloquent\Collection
    {
        return self::query()
            ->where('mode', $mode)
            ->whereNull('parent_id')
            ->whereHas('children', fn ($query) => $query->where('mode', $mode))
            ->with(['children' => fn ($query) => $query->where('mode', $mode)->orderByDesc('sort_index')->orderByDesc('id')])
            ->orderByDesc('sort_index')
            ->orderByDesc('id')
            ->get();
    }

    public static function listUploadOptions(int $mode, ?int $includeCategoryId = null): \Illuminate\Database\Eloquent\Collection
    {
        $options = self::query()
            ->where('mode', $mode)
            ->whereDoesntHave('children', fn ($query) => $query->where('mode', $mode))
            ->with('parent:id,name')
            ->orderByDesc('sort_index')
            ->orderByDesc('id')
            ->get(['id', 'mode', 'name', 'parent_id']);

        if ($includeCategoryId !== null && !$options->contains('id', $includeCategoryId)) {
            $currentCategory = self::query()
                ->where('mode', $mode)
                ->whereKey($includeCategoryId)
                ->first(['id', 'mode', 'name', 'parent_id']);
            if ($currentCategory) {
                $options->prepend($currentCategory);
            }
        }

        return $options;
    }

    public static function listFilterOptions(int $mode): \Illuminate\Database\Eloquent\Collection
    {
        return self::query()
            ->where('mode', $mode)
            ->with('parent:id,name')
            ->orderByDesc('sort_index')
            ->orderByDesc('id')
            ->get(['id', 'mode', 'name', 'parent_id']);
    }

    public static function isUploadable(int $categoryId, int $mode): bool
    {
        return self::query()
            ->whereKey($categoryId)
            ->where('mode', $mode)
            ->whereDoesntHave('children', fn ($query) => $query->where('mode', $mode))
            ->exists();
    }

    public static function expandMenuFilterIds(array $categoryIds, int $mode): array
    {
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
        if (empty($categoryIds)) {
            return [];
        }

        $childrenByParent = self::query()
            ->where('mode', $mode)
            ->whereIn('parent_id', $categoryIds)
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id');

        $expandedIds = [];
        foreach ($categoryIds as $categoryId) {
            if ($childrenByParent->has($categoryId)) {
                $expandedIds[] = $categoryId;
                foreach ($childrenByParent->get($categoryId) as $child) {
                    $expandedIds[] = (int) $child->id;
                }
            } else {
                $expandedIds[] = $categoryId;
            }
        }

        return array_values(array_unique($expandedIds));
    }
}
