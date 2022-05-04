<?php

namespace Meltor\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Meltor\Meltor;

class MeltorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'meltor:generate
                {--f|force   : Execute without confirmation. }
                {--t|testrun : Perform a db structure comparison testrun (will need to drop and restore your DB). }
                {--r|restore : Restore the db backup from a previous run, stops after that. }
                ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new migration based on the current MySQL database';

    // Internals
    protected array $indexes = [];
    protected bool $canAccessInnoDbIndexes = false;
    protected bool $warnAboutFloat = false;
    protected string $databaseName = '';
    protected ?ConnectionInterface $dataConnection;
    protected ?ConnectionInterface $schemaConnection;
    protected ?Collection $structure;
    protected ?Meltor $meltor;

    /**
     * Execute the console command.
     *
     * @return int
     * @throws Exception
     */
    public function handle(): int
    {
        $this->meltor           = app('meltor');
        $this->dataConnection   = DB::connection($this->meltor->config('connection.data'));
        $this->databaseName     = $this->dataConnection->getDatabaseName();
        $schemaConnectionName   = $this->meltor->config('connection.schema');

        try {
            $this->schemaConnection = DB::connection($schemaConnectionName);
        }
        catch (Exception $exception) {
            $this->warn(sprintf('Please configure a database connection named %s that points to the information_schema database. See README.md.', $schemaConnectionName));
            return 1;
        }

        if ($this->option('restore')) {
            $this->meltor->restoreBackup($this);

            return 0;
        }

        $this->newLine();
        $this->line(sprintf('Meltor tries to consolidate all past migrations, based on this system\'s "%s" DB', $this->databaseName));
        $this->line('Make sure all your systems have been deployed, and that this system has your current, production database structure.');

        if ($this->option('testrun')) {
            $this->newLine();
            $this->line('Test run enabled');
            if (!$this->option('force')) {
                $ready = $this->ask(
                    'Please confirm that you have deleted all old migrations, except ones that change framework or package created tables (y|N)'
                );

                if ($ready != 'y') {
                    $this->info('Aborting');

                    return 0;
                }
            }
        }

        $this->newLine();
        $this->line(sprintf('Reading structure information from connection %s, database %s', $this->meltor->config('connection.data'), $this->databaseName));

        $this->structure   = $this->meltor->getDatabaseStructure($this->databaseName, $this->schemaConnection);
        $this->structure   = $this->meltor->getDatabaseStructure($this->databaseName, $this->schemaConnection);
        $uniqueKeys        = $this->meltor->getUniqueKeys($this->databaseName, $this->schemaConnection);
        $foreignKeys       = $this->meltor->getForeignKeys($this->databaseName, $this->schemaConnection);
        $innoDbIndexResult = $this->meltor->getIndexesFromInnoDb($this->databaseName, $this->schemaConnection);

        if (is_array($innoDbIndexResult)) {
            $this->canAccessInnoDbIndexes = true;
            $this->indexes                = $innoDbIndexResult;
        } else {
            $this->warn(sprintf('Could not read indexes via information_schema: %s', $innoDbIndexResult));
            $this->line('Instead reading indexes via Doctrine, this may change the order of the indexes within tables');
        }

        $tableMigrations      = [];
        $constraintMigrations = [];

        foreach ($this->structure as $tableName => $columns) {
            if ($tableName === 'migrations') {
                continue;
            }

            $columnMigrations           = [];
            $columnConstraintMigrations = [];

            foreach ($columns->sortBy('ORDINAL_POSITION') as $column) {
                if ($column->DATA_TYPE === 'float') {
                    $this->warnAboutFloat = true;
                }

                $columnMigrations[] = $this->meltor->generateColumnMigration($column);
            }

            foreach ($uniqueKeys->get($tableName) ?? [] as $uniqueKey) {
                $columnMigrations[] = $this->meltor->generateUniqueKeyMigration($uniqueKey);
            }

            foreach ($this->meltor->getIndexesFor($tableName, $this->canAccessInnoDbIndexes, $this->indexes, $this->dataConnection) as $indexKeyName => $indexKeyColumns) {
                $columnMigrations[] = $this->meltor->generateIndexKeyMigration($indexKeyName, $indexKeyColumns);
            }

            foreach ($foreignKeys->get($tableName) ?? [] as $foreignKey) {
                $columnConstraintMigrations[] = $this->meltor->generateForeignKeyMigration($foreignKey);
            }

            $tableMigrations[$tableName]      = $columnMigrations;
            $constraintMigrations[$tableName] = $columnConstraintMigrations;
        }

        $migrationCode      = $this->meltor->generateMigration($tableMigrations, $this->meltor->getMigrationTemplate('migrationComment'), $constraintMigrations);
        $migrationCode      = $this->meltor->beautify($migrationCode);
        $migrationFilePath  = $this->meltor->writeMigration($migrationCode, $this->meltor->config('migration.name'), $this);
        $showDisclaimerText = true;

        if ($this->option('testrun')) {
            $showDisclaimerText = $this->testrun();
        }

        if ($showDisclaimerText) {
            $this->newLine();
            $this->line(
                sprintf('Test and inspect "%s" to make sure the migration fits your requirements', $migrationFilePath)
            );
            $this->line('You can then delete all prior migrations and commit');
            $this->warn('It may be necessary to keep migrations that alter tables created by frameworks or packages!');
        }

        if ($this->warnAboutFloat) {
            $this->warn('FLOAT columns may be turned to DOUBLE by Laravel!');
        }

        $this->newLine();

        return 0;
    }

    /**
     * Compares the database structure before and after the new migration.
     *
     * Returns true success, but also if there are still changes to review.
     * Returns false on complete failures.
     *
     * @return bool
     */
    protected function testrun(): bool
    {
        $success                 = false;
        $beforeStructureFileName = $this->meltor->combinePath(
            $this->meltor->config('testrun.folder'),
            $this->meltor->config('testrun.beforeStructureFileName')
        );
        $afterStructureFileName  = $this->meltor->combinePath(
            $this->meltor->config('testrun.folder'),
            $this->meltor->config('testrun.afterStructureFileName')
        );

        // Reading existing database structure.
        $this->newLine();
        $this->line('Performing structure comparison test run');
        $this->meltor->writeStructureDump($beforeStructureFileName, $this);

        // Backup existing database.
        $this->newLine();
        $this->line('Backing up database before applying temporary changes');
        $this->call('protector:export', ['--file' => $this->meltor->config('testrun.backupFileName')]);

        // Try out new migration.
        $this->newLine();
        $this->line('Running consolidated migration');
        $this->call('migrate:fresh', []);

        // Reading structure generated by the new migration.
        $this->meltor->writeStructureDump($afterStructureFileName, $this);

        // Restore existing database.
        $this->meltor->restoreBackup($this);

        // Compare both structures to make sure the new, consolidated migration does what all the previous ones did.
        $this->line('Comparing structure dumps');

        $fileSizeBefore  = filesize($beforeStructureFileName);
        $fileSizeAfter   = filesize($afterStructureFileName);
        $beforeStructure = $this->meltor->readAndCleanStructure($beforeStructureFileName);
        $afterStructure  = $this->meltor->readAndCleanStructure($afterStructureFileName);

        file_put_contents($beforeStructureFileName, $beforeStructure);
        file_put_contents($afterStructureFileName, $afterStructure);

        if (!$fileSizeBefore || !$fileSizeAfter) {
            $this->warn('At least one structure file is empty, that\'s not good!');
        } elseif ($beforeStructure == $afterStructure) {
            $this->info('Success! the structure files are identical!');
            $success = true;
        } else {
            $this->warn('The structure files are not identical, please check!');
            $success = true;
        }

        $this->line($beforeStructureFileName);

        if (!$fileSizeBefore) {
            $this->warn('Something went wrong, the before file is empty!');
        }

        $this->line($afterStructureFileName);

        if (!$fileSizeAfter) {
            $this->warn('Something went wrong, the after file is empty!');
        }

        return $success;
    }
}
