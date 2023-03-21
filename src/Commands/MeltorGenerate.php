<?php

namespace Meltor\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Meltor\Meltor;

class MeltorGenerate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'meltor:generate
                {--f|force               : Execute without confirmation. }
                {--i|ignoreProblems      : Leave out structures that cannot be processed. }
                {--s|separateForeignKeys : Put foreign keys at the end of the file. Avoids problems with constraint checks. }
                {--t|testrun             : Perform a db structure comparison testrun (this will drop and restore your DB). }
                ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new migration based on the current MySQL database';

    // Internals
    protected array $indexes = [];
    protected array $problems = [];
    protected array $spatialIndexes = [];
    protected array $primaryKeyIsSetFor = [];
    protected bool $canAccessInnoDbIndexes = false;
    protected bool $ignoreProblems = false;
    protected bool $warnAboutFloat = false;
    protected bool $separateForeignKeys = false;
    protected string $databaseName = '';
    protected ?ConnectionInterface $dataConnection;
    protected ?ConnectionInterface $schemaConnection;
    protected ?Collection $structure;
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
        $this->ignoreProblems      = $this->option('ignoreProblems');
        $this->separateForeignKeys = $this->option('separateForeignKeys');
        $this->dataConnection      = DB::connection($this->meltor->config('connection.data'));
        $this->databaseName        = $this->dataConnection->getDatabaseName();
        $schemaConnectionName      = $this->meltor->config('connection.schema');

        try {
            $this->schemaConnection = DB::connection($schemaConnectionName);
        } catch (Exception $exception) {
            $this->warn(sprintf('Please configure a database connection named %s that points to the information_schema database. See README.md.', $schemaConnectionName));
            return 1;
        }

        if ($this->meltor->backupExists()) {
            if ($this->ask('A previous test run seems to have aborted. Restore database backup before starting? (y|N)', 'n') == 'y') {
                $this->meltor->restoreBackup($this);
            }
        }

        $this->newLine();
        $this->line(sprintf('Meltor tries to consolidate all past migrations, based on this system\'s "%s" DB', $this->databaseName));
        $this->line('Make sure all your systems have been deployed, and that this system has your current, production database structure.');

        if ($this->option('testrun')) {
            $this->newLine();
            $this->line('Test run enabled');
            if (!$this->option('force')) {
                $ready = $this->ask('Please confirm that you have deleted all old migrations, except ones that change framework or package created tables (y|N)');

                if ($ready != 'y') {
                    $this->info('Aborting');

                    return 0;
                }
            }
        }

        $this->newLine();
        $this->line(sprintf('Reading structure information from connection %s, database %s', $this->meltor->config('connection.data'), $this->databaseName));

        $this->structure   = $this->meltor->getDatabaseStructure($this->databaseName, $this->schemaConnection);
        $uniqueKeys        = $this->meltor->getUniqueKeys($this->databaseName, $this->schemaConnection);
        $foreignKeys       = $this->meltor->getForeignKeys($this->databaseName, $this->schemaConnection);
        $innoDbIndexResult = $this->meltor->getIndexesFromInnoDb($this->databaseName, $this->schemaConnection, 0);

        if($this->structure->isEmpty()) {
            $this->error('The database is empty');
            $this->newLine();

            return 0;
        }

        if (is_array($innoDbIndexResult)) {
            $this->canAccessInnoDbIndexes = true;
            $this->indexes                = $innoDbIndexResult;
            $this->spatialIndexes         = $this->meltor->getIndexesFromInnoDb($this->databaseName, $this->schemaConnection, 64);
        } else {
            $this->warn(sprintf('Could not read indexes via information_schema: %s', $innoDbIndexResult));
            $this->line('Instead reading indexes via Laravel\'s DoctrineSchemaManager, which may be missing details. See README.md.');
        }

        $tableMigrations      = [];
        $constraintMigrations = [];
        $rawTypeExceptions    = $this->meltor->config('mysql.exceptionRawTypes');

        foreach ($this->structure as $tableName => $columns) {
            $columnMigrations           = [];
            $columnMigrationsRaw        = [];
            $columnConstraintMigrations = [];
            $rawExceptions              = [];

            foreach ($columns->sortBy('ORDINAL_POSITION') as $column) {
                $columnName = $column->COLUMN_NAME;
                $columnType = $column->DATA_TYPE;

                if (array_key_exists($columnType, $rawTypeExceptions)) {

                    // MySQL DATA_TYPES not supported by Laravel for which we have a solution
                    $columnMigrationsRaw[] = $this->meltor->generateRawColumnMigration(
                        $tableName,
                        $columnName,
                        $rawTypeExceptions[$columnType],
                    );
                } else {

                    // Laravel supported and unknown TYPES.
                    if ($column->DATA_TYPE === 'float') {
                        $this->warnAboutFloat = true;
                    }

                $columnMigration = $this->meltor->generateMigrationColumn($column, $this->ignoreProblems);

                    if (!$columnMigration) {
                        $this->problems['nonGeneratedColumns'][$tableName][] = $columnName;
                        $this->error(sprintf('Column "%s.%s" of type %s could not be generated', $tableName, $columnName, $column->DATA_TYPE));
                    } else {
                        $columnMigrations[] = $columnMigration;

                        if (str_contains($columnMigration, '->increments(') || str_contains($columnMigration, '->id(')) {
                            $this->primaryKeyIsSetFor[$tableName] = true;
                        }
                    }
                }
            }

            // Unique Keys.
            foreach ($uniqueKeys->get($tableName) ?? [] as $uniqueKey) {
                $columnMigrations[] = $this->meltor->generateMigrationUniqueKey($uniqueKey, $this->problems['nonGeneratedColumns'] ?? [], $this);
            }

            // Index Keys.
            foreach ($this->meltor->getIndexesFor($tableName, $this->canAccessInnoDbIndexes, $this->indexes, $this->dataConnection, true) as $indexKeyName => $indexKeyColumns) {
                $columnMigrations[] = $this->meltor->generateMigrationIndexKey($indexKeyName, $indexKeyColumns);
            }

            // Primary Key
            if (!($this->primaryKeyIsSetFor[$tableName] ?? false) && $primaryKey = $this->meltor->getPrimaryKeyFor($tableName, $this->dataConnection)) {
                $columnMigrations[] = $this->meltor->generateMigrationPrimaryKey($primaryKey);
            }

            // Spatial Keys.
            foreach (
                $this->meltor->getIndexesFor(
                    $tableName,
                    $this->canAccessInnoDbIndexes,
                    $this->spatialIndexes,
                    $this->dataConnection,
                    false
                ) as $indexKeyName => $indexKeyColumns
            ) {
                $columnMigrations[] = $this->meltor->generateMigrationIndexKey($indexKeyName, $indexKeyColumns, true);
            }

            // Foreign Keys.
            foreach ($foreignKeys->get($tableName)?->groupBy('CONSTRAINT_NAME') ?? [] as $foreignKey) {
                $foreignKeyMigration = $this->meltor->generateMigrationForeignKey($foreignKey, $this->ignoreProblems, $this);
                if ($this->separateForeignKeys) {
                    $columnConstraintMigrations[] = $foreignKeyMigration;
                } else {
                    $columnMigrations[] = $foreignKeyMigration;
                }
            }

            $tableMigrations[$tableName]['laravel'] = $columnMigrations;
            $tableMigrations[$tableName]['raw']     = $columnMigrationsRaw;

            if ($this->separateForeignKeys) {
                $constraintMigrations[$tableName] = $columnConstraintMigrations;
            }
        }

        $migrationCode      = $this->meltor->generateMigration($tableMigrations, $this->meltor->getMigrationTemplate('migrationComment'), $constraintMigrations, $rawExceptions);
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
        $success        = false;
        $beforeFilePath = $this->meltor->getBeforeTestrunFilePath();
        $afterFilePath  = $this->meltor->getAfterTestrunFilePath();

        // Reading existing database structure.
        $this->newLine();
        $this->line('Performing structure comparison test run');
        $this->meltor->writeStructureDump($beforeFilePath, $this);

        // Backup existing database.
        $this->newLine();
        $this->line('Backing up database before applying temporary changes');
        $this->call('protector:export', ['--file' => $this->meltor->config('testrun.backupFileName')]);

        // Try out new migration.
        $this->newLine();
        $this->line('Running consolidated migration');
        $this->call('migrate:fresh', []);

        // Reading structure generated by the new migration.
        $this->meltor->writeStructureDump($afterFilePath, $this);

        // Compare both structures to make sure the new, consolidated migration does what all the previous ones did.
        $this->newLine();
        $this->line('Comparing structure dumps');

        $fileSizeBefore  = filesize($beforeFilePath);
        $fileSizeAfter   = filesize($afterFilePath);
        $structureBefore = $this->meltor->readAndCleanStructure($beforeFilePath);
        $structureAfter  = $this->meltor->readAndCleanStructure($afterFilePath);

        file_put_contents($beforeFilePath, $structureBefore);
        file_put_contents($afterFilePath, $structureAfter);

        if (!$fileSizeBefore || !$fileSizeAfter) {
            $this->warn('At least one structure file is empty, that\'s not good!');
        } elseif ($structureBefore == $structureAfter) {
            $this->info('Success! the structure files are identical!');
            $success = true;
        } else {
            $this->warn('The structure files are not identical. Run php artisan meltor:diff to investigate.');
            $success = true;
        }

        $this->line($beforeFilePath);

        if (!$fileSizeBefore) {
            $this->warn('Something went wrong, the before file is empty!');
        }

        $this->line($afterFilePath);

        if (!$fileSizeAfter) {
            $this->warn('Something went wrong, the after file is empty!');
        }

        // Restore existing database.
        $this->meltor->restoreBackup($this);

        return $success;
    }
}
