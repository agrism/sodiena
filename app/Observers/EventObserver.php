<?php

namespace App\Observers;

use App\Jobs\RefreshSitemapJob;
use App\Models\Event;

class EventObserver
{
    /**
     * Handle the Event "saved" event.
     */
    public function saved(Event $event): void
    {
        // Dispatch job when publication status, date, or URL identifiers change
        if ($event->wasChanged(['published_at', 'status', 'slug', 'internal_image_url', 'image_url', 'start_at', 'end_at']) || ($event->wasRecentlyCreated && $event->isPublished())) {
            RefreshSitemapJob::dispatch();
        }
    }

    /**
     * Handle the Event "deleted" (soft delete) event.
     */
    public function deleted(Event $event): void
    {
        if ($event->published_at !== null) {
            RefreshSitemapJob::dispatch();
        }
    }

    /**
     * Handle the Event "restored" event.
     */
    public function restored(Event $event): void
    {
        if ($event->isPublished()) {
            RefreshSitemapJob::dispatch();
        }
    }
}
