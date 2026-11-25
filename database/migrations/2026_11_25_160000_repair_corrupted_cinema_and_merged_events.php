<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Sanitizes cinema events and restores hijacked non-cinema events.
     */
    public function up(): void
    {
        $today = Carbon::today()->startOfDay();

        $sourceApollo = DB::table('sources')->where('slug', 'apollo-kino')->first();
        $sourceForum = DB::table('sources')->where('slug', 'forum-cinemas')->first();
        $sourceBilesuServiss = DB::table('sources')->where('slug', 'bilesu-serviss')->first();
        $sourceBezRindas = DB::table('sources')->where('slug', 'bezrindas')->first();
        $sourceBilesuParadize = DB::table('sources')->where('slug', 'bilesu-paradize')->first();
        $sourceFienta = DB::table('sources')->where('slug', 'fienta')->first();

        // 1. Repair Cinema Shows (Apollo Kino & Forum Cinemas)
        $cinemaEvents = DB::table('events')
            ->where(function ($q) use ($today) {
                $q->where('start_at', '>=', $today)
                  ->orWhere('end_at', '>=', $today);
            })
            ->where(function ($q) {
                $q->whereIn('source_slug', ['apollo-kino', 'forum-cinemas'])
                  ->orWhere('ticket_url', 'LIKE', '%apollokino.lv/websales/show/%')
                  ->orWhere('ticket_url', 'LIKE', '%forumcinemas.lv/websales/show/%');
            })
            ->get();

        foreach ($cinemaEvents as $event) {
            $ticketUrl = $event->ticket_url ?? '';
            $update = [];
            $raw = !empty($event->raw_data) ? json_decode($event->raw_data, true) : [];
            if (!is_array($raw)) {
                $raw = [];
            }
            $rawChanged = false;

            // Apollo Kino show
            if (str_contains($ticketUrl, 'apollokino.lv/websales/show/')) {
                if (preg_match('#/show/(\d+)#', $ticketUrl, $m)) {
                    $showId = 'show_' . $m[1];
                    if ($event->source_external_id !== $showId) {
                        $update['source_external_id'] = $showId;
                    }
                    if ($event->source_slug !== 'apollo-kino') {
                        $update['source_slug'] = 'apollo-kino';
                    }
                    if ($sourceApollo && $event->source_id !== $sourceApollo->id) {
                        $update['source_id'] = $sourceApollo->id;
                    }

                    // Clean raw_data merged_external_ids so foreign IDs are not trapped
                    if (!empty($raw['merged_external_ids']) && is_array($raw['merged_external_ids'])) {
                        $cleanedMerged = ['apollo-kino' => $showId];
                        if ($raw['merged_external_ids'] !== $cleanedMerged) {
                            $raw['merged_external_ids'] = $cleanedMerged;
                            $rawChanged = true;
                        }
                    }
                }
            }
            // Forum Cinemas show
            elseif (str_contains($ticketUrl, 'forumcinemas.lv/websales/show/')) {
                if (preg_match('#/show/(\d+)#', $ticketUrl, $m)) {
                    $showId = 'show_' . $m[1];
                    if ($event->source_external_id !== $showId) {
                        $update['source_external_id'] = $showId;
                    }
                    if ($event->source_slug !== 'forum-cinemas') {
                        $update['source_slug'] = 'forum-cinemas';
                    }
                    if ($sourceForum && $event->source_id !== $sourceForum->id) {
                        $update['source_id'] = $sourceForum->id;
                    }

                    if (!empty($raw['merged_external_ids']) && is_array($raw['merged_external_ids'])) {
                        $cleanedMerged = ['forum-cinemas' => $showId];
                        if ($raw['merged_external_ids'] !== $cleanedMerged) {
                            $raw['merged_external_ids'] = $cleanedMerged;
                            $rawChanged = true;
                        }
                    }
                }
            }

            // Clean description if Forum Cinemas session info leaked into Apollo Kino event
            if (($event->source_slug === 'apollo-kino' || str_contains($ticketUrl, 'apollokino.lv')) && str_contains($event->description ?? '', 'Kinoteātris: Forum Cinemas')) {
                $cleanedDesc = preg_replace("/\n*--- Seansa informācija ---\nKinoteātris: Forum Cinemas[^\n]*\n.*$/s", '', $event->description);
                $update['description'] = trim($cleanedDesc);

                DB::table('event_translations')
                    ->where('event_id', $event->id)
                    ->where('locale', 'lv')
                    ->update([
                        'description' => trim($cleanedDesc),
                        'updated_at' => now(),
                    ]);
            }

            // Clean description if it contains exhibition/museum keywords and no movie keywords,
            // but short_description already contains the clean movie synopsis
            $descLower = mb_strtolower($event->description ?? '', 'UTF-8');
            if ((str_contains($descLower, 'izstād') || str_contains($descLower, 'muzej')) && !str_contains($descLower, 'film') && !empty($event->short_description)) {
                $update['description'] = $event->short_description;

                // Update translation
                DB::table('event_translations')
                    ->where('event_id', $event->id)
                    ->where('locale', 'lv')
                    ->update([
                        'description' => $event->short_description,
                        'updated_at' => now(),
                    ]);

                // Purge stale foreign translations for this event
                DB::table('event_translations')
                    ->where('event_id', $event->id)
                    ->where('locale', '!=', 'lv')
                    ->delete();
            }

            // Reset stale entertainment type if it was exhibition or chill
            if (in_array($event->entertainment_type, ['exhibition', 'chill'], true)) {
                $update['entertainment_type'] = null;
            }

            if ($rawChanged) {
                $update['raw_data'] = json_encode($raw, JSON_UNESCAPED_UNICODE);
            }

            if (!empty($update)) {
                $update['updated_at'] = now();
                DB::table('events')->where('id', $event->id)->update($update);
            }
        }

        // 2. Repair Specific Hijacked Non-Cinema Events
        // ID 1025: Princešu Olimpiskās spēles (Mihaila Čehova teātris)
        if ($e1025 = DB::table('events')->where('id', 1025)->first()) {
            if ($e1025->start_at >= $today || $e1025->end_at >= $today) {
                $title = 'Princešu Olimpiskās spēles';
                $slug = Str::slug($title) . '-' . substr(md5('1025-fix'), 0, 6);
                $update1025 = [
                    'title' => $title,
                    'slug' => $slug,
                    'source_slug' => 'bilesu-serviss',
                    'source_id' => $sourceBilesuServiss?->id ?: $e1025->source_id,
                    'source_external_id' => 'bilesuserviss-RKNQYQDGGT',
                    'updated_at' => now(),
                ];
                $raw1025 = !empty($e1025->raw_data) ? json_decode($e1025->raw_data, true) : [];
                if (is_array($raw1025) && isset($raw1025['merged_external_ids']['apollo-kino'])) {
                    unset($raw1025['merged_external_ids']['apollo-kino']);
                    $update1025['raw_data'] = json_encode($raw1025, JSON_UNESCAPED_UNICODE);
                }
                DB::table('events')->where('id', 1025)->update($update1025);
                DB::table('event_translations')->where('event_id', 1025)->where('locale', 'lv')->update([
                    'title' => $title,
                    'slug' => $slug,
                    'updated_at' => now(),
                ]);
            }
        }

        // ID 3717: Biznesa spēle "Finanses un investīcijas"
        if ($e3717 = DB::table('events')->where('id', 3717)->first()) {
            if ($e3717->start_at >= $today || $e3717->end_at >= $today) {
                $title = 'Biznesa spēle: Finanses un investīcijas';
                $slug = Str::slug($title) . '-' . substr(md5('3717-fix'), 0, 6);
                $update3717 = [
                    'title' => $title,
                    'slug' => $slug,
                    'source_slug' => 'bezrindas',
                    'source_id' => $sourceBezRindas?->id ?: $e3717->source_id,
                    'source_external_id' => 'bezrindas-13473-79010',
                    'updated_at' => now(),
                ];
                $raw3717 = !empty($e3717->raw_data) ? json_decode($e3717->raw_data, true) : [];
                if (is_array($raw3717) && isset($raw3717['merged_external_ids']['apollo-kino'])) {
                    unset($raw3717['merged_external_ids']['apollo-kino']);
                    $update3717['raw_data'] = json_encode($raw3717, JSON_UNESCAPED_UNICODE);
                }
                DB::table('events')->where('id', 3717)->update($update3717);
                DB::table('event_translations')->where('event_id', 3717)->where('locale', 'lv')->update([
                    'title' => $title,
                    'slug' => $slug,
                    'updated_at' => now(),
                ]);
            }
        }

        // ID 12932: Red Mic Comedy: Helsinki
        if ($e12932 = DB::table('events')->where('id', 12932)->first()) {
            if ($e12932->start_at >= $today || $e12932->end_at >= $today) {
                $title = 'Red Mic Comedy: Helsinki';
                $slug = Str::slug($title) . '-' . substr(md5('12932-fix'), 0, 6);
                $update12932 = [
                    'title' => $title,
                    'slug' => $slug,
                    'source_slug' => 'fienta',
                    'source_id' => $sourceFienta?->id ?: $e12932->source_id,
                    'source_external_id' => 'fienta-red-mic-helsinki26d',
                    'updated_at' => now(),
                ];
                $raw12932 = !empty($e12932->raw_data) ? json_decode($e12932->raw_data, true) : [];
                if (is_array($raw12932) && isset($raw12932['merged_external_ids']['apollo-kino'])) {
                    unset($raw12932['merged_external_ids']['apollo-kino']);
                    $update12932['raw_data'] = json_encode($raw12932, JSON_UNESCAPED_UNICODE);
                }
                DB::table('events')->where('id', 12932)->update($update12932);
                DB::table('event_translations')->where('event_id', 12932)->where('locale', 'lv')->update([
                    'title' => $title,
                    'slug' => $slug,
                    'updated_at' => now(),
                ]);
            }
        }

        // ID 12991: Viirus kausikortti
        if ($e12991 = DB::table('events')->where('id', 12991)->first()) {
            if ($e12991->start_at >= $today || $e12991->end_at >= $today) {
                $title = 'Viirus kausikortti';
                $slug = Str::slug($title) . '-' . substr(md5('12991-fix'), 0, 6);
                $update12991 = [
                    'title' => $title,
                    'slug' => $slug,
                    'source_slug' => 'fienta',
                    'source_id' => $sourceFienta?->id ?: $e12991->source_id,
                    'source_external_id' => 'fienta-viirus-kausikortti-syksy-2026',
                    'updated_at' => now(),
                ];
                $raw12991 = !empty($e12991->raw_data) ? json_decode($e12991->raw_data, true) : [];
                if (is_array($raw12991) && isset($raw12991['merged_external_ids']['apollo-kino'])) {
                    unset($raw12991['merged_external_ids']['apollo-kino']);
                    $update12991['raw_data'] = json_encode($raw12991, JSON_UNESCAPED_UNICODE);
                }
                DB::table('events')->where('id', 12991)->update($update12991);
                DB::table('event_translations')->where('event_id', 12991)->where('locale', 'lv')->update([
                    'title' => $title,
                    'slug' => $slug,
                    'updated_at' => now(),
                ]);
            }
        }

        // ID 3206: Kā aplaupīt banku (Biļešu Paradīze theater play)
        if ($e3206 = DB::table('events')->where('id', 3206)->first()) {
            if ($e3206->start_at >= $today || $e3206->end_at >= $today) {
                $update3206 = [
                    'source_slug' => 'bilesu-paradize',
                    'source_id' => $sourceBilesuParadize?->id ?: $e3206->source_id,
                    'source_external_id' => 'bp-session-178373',
                    'updated_at' => now(),
                ];
                $raw3206 = !empty($e3206->raw_data) ? json_decode($e3206->raw_data, true) : [];
                if (is_array($raw3206) && isset($raw3206['merged_external_ids'])) {
                    unset($raw3206['merged_external_ids']['apollo-kino'], $raw3206['merged_external_ids']['forum-cinemas']);
                    $update3206['raw_data'] = json_encode($raw3206, JSON_UNESCAPED_UNICODE);
                }
                DB::table('events')->where('id', 3206)->update($update3206);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // One-way data sanitization migration
    }
};
