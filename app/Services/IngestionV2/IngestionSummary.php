<?php

namespace App\Services\IngestionV2;

class IngestionSummary
{
    public int $itemsFound = 0;
    public int $itemsCreated = 0;
    public int $itemsUpdated = 0;
    public int $itemsDeduplicated = 0;
    public int $itemsCancelled = 0;
    public array $errors = [];
    public float $durationSeconds = 0.0;

    public function status(): string
    {
        if (empty($this->errors)) {
            return 'success';
        }
        return ($this->itemsCreated > 0 || $this->itemsUpdated > 0 || $this->itemsDeduplicated > 0)
            ? 'partial'
            : 'failed';
    }

    public function isSuccess(): bool
    {
        return empty($this->errors);
    }

    public function toArray(): array
    {
        return [
            'items_found' => $this->itemsFound,
            'items_created' => $this->itemsCreated,
            'items_updated' => $this->itemsUpdated,
            'items_deduplicated' => $this->itemsDeduplicated,
            'items_cancelled' => $this->itemsCancelled,
            'duration_seconds' => $this->durationSeconds,
            'status' => $this->status(),
            'errors' => $this->errors,
        ];
    }
}
