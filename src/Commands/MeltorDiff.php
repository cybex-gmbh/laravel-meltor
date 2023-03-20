<?php

namespace Meltor\Commands;

use Exception;
use Illuminate\Console\Command;
use Meltor\Meltor;
use Jfcherng\Diff\DiffHelper;

class MeltorDiff extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'meltor:diff';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Visually compare the results of a test run';

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
        $this->meltor              = app('meltor');
        $beforeStructureFileName = $this->meltor->beforeStructureFilePath();
        $afterStructureFileName  = $this->meltor->afterStructureFilePath();

        if (!file_exists($beforeStructureFileName)) {
            $this->warn(sprintf('Could not find "%s". Run a test run first.', $beforeStructureFileName));

            return 0;
        }

        if (!file_exists($afterStructureFileName)) {
            $this->warn(sprintf('Could not find "%s". Run a test run first.', $afterStructureFileName));

            return 0;
        }

        $this->newLine();
        $this->info('Comparing the test run database structure dumps:');
        $this->line($beforeStructureFileName);
        $this->line($afterStructureFileName);
        $this->newLine();

        $diff = DiffHelper::calculateFiles($beforeStructureFileName, $afterStructureFileName, 'Unified');

        $this->line($diff ?: 'The files are identical!');
        $this->newLine();

        return 0;
    }
}



