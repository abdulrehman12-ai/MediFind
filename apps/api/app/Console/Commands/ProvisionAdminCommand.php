<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Staff\StaffInvitation;
use Illuminate\Console\Command;

final class ProvisionAdminCommand extends Command
{
    protected $signature = 'medifind:provision-admin {email}';

    protected $description = 'Create a single-use administrator invitation and print the accept link once.';

    public function handle(StaffInvitation $invitations): int
    {
        $email = (string) $this->argument('email');
        $token = $invitations->inviteAdmin($email);
        $this->line(url('/invitations/accept?token='.$token));

        return self::SUCCESS;
    }
}
