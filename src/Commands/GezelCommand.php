<?php

namespace Onomahq\Gezel\Commands;

use Illuminate\Console\Command;

class GezelCommand extends Command
{
    public $signature = 'laravel-gezel';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
