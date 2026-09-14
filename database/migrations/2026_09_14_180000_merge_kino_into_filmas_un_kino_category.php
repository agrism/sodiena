<?php

use App\Models\Category;
use App\Models\CategoryTranslation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create or retrieve the unified "Filmas & Kino" category
        $targetCategory = Category::firstOrCreate(
            ['slug' => 'filmas-kino'],
            [
                'name' => 'Filmas & Kino',
                'icon' => 'film',
                'color' => 'teal',
                'description' => 'Kino seansi, jaunākās filmas, kinofestivāli, pirmizrādes un brīvdabas kino',
                'order' => 6,
            ]
        );

        CategoryTranslation::updateOrCreate(
            ['category_id' => $targetCategory->id, 'locale' => 'lv'],
            ['name' => 'Filmas & Kino']
        );

        // 2. Find old categories to merge: "Kino", "Kino & Filmas"
        $oldCategories = Category::whereIn('slug', ['kino', 'kino-filmas'])
            ->orWhereIn('name', ['Kino', 'Kino & Filmas', 'Kino un filmas'])
            ->where('id', '!=', $targetCategory->id)
            ->get();

        foreach ($oldCategories as $oldCat) {
            // Get all event IDs attached to old category
            $eventIds = DB::table('category_event')
                ->where('category_id', $oldCat->id)
                ->pluck('event_id');

            foreach ($eventIds as $eventId) {
                // Attach to target category if not already attached
                $exists = DB::table('category_event')
                    ->where('category_id', $targetCategory->id)
                    ->where('event_id', $eventId)
                    ->exists();

                if (!$exists) {
                    DB::table('category_event')->insert([
                        'category_id' => $targetCategory->id,
                        'event_id' => $eventId,
                    ]);
                }
            }

            // Remove relations and delete old category
            DB::table('category_event')->where('category_id', $oldCat->id)->delete();
            CategoryTranslation::where('category_id', $oldCat->id)->delete();
            $oldCat->delete();
        }
    }

    public function down(): void
    {
        // Keep target category intact
    }
};
