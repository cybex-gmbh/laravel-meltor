<?php

namespace Meltor\Commands;

use Exception;
use Illuminate\Console\Command;
use Meltor\Meltor;

class MeltorRestore extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'meltor:restore';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restore the database backup of a failed test run';

    // Internals
    protected ?Meltor $meltor;

    /**
     * Execute the console command.
     *
     * @return int
     *
     * @throws Exception
     */
    public function handle(): int
    {
        $this->meltor = app('meltor');
        $this->meltor->restoreBackup($this);

        return 0;
    }
}



