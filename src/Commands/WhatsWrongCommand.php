<?php

namespace Vblinden\WhatsWrong\Commands;

use Illuminate\Console\Command;

class WhatsWrongCommand extends Command
{
    public $signature = 'laravel-whats-wrong';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
