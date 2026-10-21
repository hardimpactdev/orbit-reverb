<?php

namespace App\Reverb;

use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Protocols\Pusher\Server;

class AppScopedPusherServer extends Server
{
    /**
     * Scope membership checks to the sender after another app or a housekeeping job used the manager.
     */
    public function message(Connection $from, string $message): void
    {
        $this->channels->for($from->app());

        parent::message($from, $message);
    }
}
