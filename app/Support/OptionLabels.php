<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Department and Division names are not unique (task.md §2.36), so two
 * dropdown options can read the same. The OPTION VALUE is always the record's
 * own id; this only adds a visible "#id" to the LABEL, and only for names
 * that actually repeat, so the common case looks exactly as before.
 *
 * Sets `option_label` on each model in place (an in-memory attribute, never
 * saved). Models read it through their `option_label` accessor, which falls
 * back to the plain name when nothing was applied.
 */
class OptionLabels
{
    public static function departments(Collection $departments): Collection
    {
        $counts = $departments->countBy(fn ($department) => self::normalize($department->name));

        return $departments->each(function ($department) use ($counts) {
            $department->option_label = $counts->get(self::normalize($department->name), 1) > 1
                ? $department->name.' #'.$department->id
                : $department->name;
        });
    }

    /**
     * A division is told apart from another only when BOTH its name and its
     * member type match (the type is already shown beside the name).
     */
    public static function divisions(Collection $divisions): Collection
    {
        $counts = $divisions->countBy(fn ($division) => self::normalize($division->name).'|'.$division->member_type);

        return $divisions->each(function ($division) use ($counts) {
            $division->option_label = $counts->get(self::normalize($division->name).'|'.$division->member_type, 1) > 1
                ? $division->name.' #'.$division->id
                : $division->name;
        });
    }

    private static function normalize(?string $name): string
    {
        return mb_strtolower(trim((string) $name));
    }
}
