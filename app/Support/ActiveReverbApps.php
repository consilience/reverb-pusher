<?php

namespace App\Support;

use App\Models\ReverbApp;
use Illuminate\Support\Collection;

/**
 * The one database read the Reverb server needs before it can accept
 * connections. It sits in its own container-resolved class so a test can
 * swap in a stand-in that fails the way an unreachable database does,
 * without pointing the suite at a real host that is down.
 */
class ActiveReverbApps
{
    /**
     * @return Collection<int, ReverbApp>
     */
    public function fetch(): Collection
    {
        return ReverbApp::active()->get();
    }
}
