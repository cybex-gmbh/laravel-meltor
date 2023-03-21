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
        $this->meltor   = app('meltor');
        $beforeFilePath = $this->meltor->getBeforeTestrunFilePath();
        $afterFilePath  = $this->meltor->getAfterTestrunFilePath();

        if (!file_exists($beforeFilePath)) {
            $this->warn(sprintf('Could not find "%s". Run a test run first.', $beforeFilePath));

            return 0;
        }

        if (!file_exists($afterFilePath)) {
            $this->warn(sprintf('Could not find "%s". Run a test run first.', $afterFilePath));

            return 0;
        }

        $this->newLine();
        $this->info('Comparing the test run database structure dumps:');
        $this->line($beforeFilePath);
        $this->line($afterFilePath);
        $this->newLine();

        $diff = DiffHelper::calculateFiles($beforeFilePath, $afterFilePath, 'Unified');

        $this->line($diff ?: 'The files are identical!');
        $this->newLine();

        return 0;
    }
}



