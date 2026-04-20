<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Progress events emitted by the SystemLifecycleManager during up/stop/down/update.
 *
 * Consumed by commands to render live progress (`system:up`, `system:stop`,
 * `system:down`, `system:update`) or ignored in JSON/non-interactive mode.
 */
enum SystemLifecycleProgress: string
{
    case Starting = 'starting';
    case Started = 'started';
    case AlreadyRunning = 'already-running';
    case Stopping = 'stopping';
    case Stopped = 'stopped';
    case AlreadyStopped = 'already-stopped';
    case Removing = 'removing';
    case Removed = 'removed';
    case Building = 'building';
    case Built = 'built';
    case PostInstallStarting = 'post-install-starting';
    case PostInstallOk = 'post-install-ok';
    case PostInstallFailed = 'post-install-failed';
}
