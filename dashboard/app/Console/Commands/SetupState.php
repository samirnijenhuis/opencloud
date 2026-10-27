<?php

namespace App\Console\Commands;

use App\Domains\Members\Models\User;
use Illuminate\Console\Command;

class SetupState extends Command
{
    protected $signature = 'opencloud:setup-state';

    protected $description = 'Print open while no owner exists, and done after';

    public function handle(): int
    {
        $this->line(User::query()->exists() ? 'done' : 'open');

        return self::SUCCESS;
    }
}
