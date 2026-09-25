<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Staff\InvitationOutbox;
use Illuminate\Console\Command;

final class DeliverInvitationsCommand extends Command
{
    protected $signature = 'medifind:deliver-invitations';

    protected $description = 'Retry invitation emails that were committed but not yet delivered.';

    public function handle(InvitationOutbox $outbox): int
    {
        $failures = $outbox->deliverOutstanding();

        if ($failures > 0) {
            $this->error('Invitation delivery left '.$failures.' message(s) pending.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
