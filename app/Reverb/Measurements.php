<?php

declare(strict_types=1);

namespace App\Reverb;

use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Events\MessageSent;

/** Counters for currently configured apps. Messages contain no retained payload or credential data. */
final class Measurements
{
    /** @var array<string, array<string, int>> */
    private array $totals = [];

    public function record(MessageReceived|MessageSent $event): void
    {
        $id = $event->connection->app()->id();
        $direction = $event instanceof MessageReceived ? 'received' : 'sent';
        $this->totals[$id][$direction.'_messages'] = ($this->totals[$id][$direction.'_messages'] ?? 0) + 1;
        $this->totals[$id][$direction.'_bytes'] = ($this->totals[$id][$direction.'_bytes'] ?? 0) + strlen($event->message);
    }

    /**
     * @param  list<string>  $appIds
     * @return array<string, array<string, int>>
     */
    public function totals(array $appIds): array
    {
        $this->totals = array_intersect_key($this->totals, array_fill_keys($appIds, true));

        return $this->totals;
    }
}
