<?php

namespace Meltor\Commands;

use DateTime;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Meltor;

class MeltorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:meltor
                {--t|testrun : Perform a db structure comparison testrun (will drop and restore your db). }
                {--r|restore : Restore the db backup from a previous run, stops after that. }
                ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates one migration based on the current MySQL database';

    // Internals
    protected string $beforeStructureFileName = '';
    protected string $afterStructureFileName = '';
    protected string $databaseName;
    protected string $defaultConnection;
    protected array $indexes;
    protected bool $canAccessInnoDbIndexes = false;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->beforeStructureFileName = storage_path(Meltor::getConfigValueForKey('testrun.beforeStructureFileName'));
        $this->afterStructureFileName  = storage_path(Meltor::getConfigValueForKey('testrun.afterStructureFileName'));
        $this->defaultConnection       = DB::getDefaultConnection();
        $this->databaseName            = Meltor::getDatabaseName($this->defaultConnection);
    }

    /**
     * Execute the console command.
     *
     * @return int
     * @throws Exception
     */
    public function handle(): int
    {
        if ($this->option('restore')) {
            $this->restoreBackup();

            return 0;
        }

        $this->newLn();
        $this->line(sprintf('Meltor tries to consolidate all past migrations, based on the current local "%s" DB', $this->databaseName));

        if ($this->option('testrun')) {
            $this->newLn();
            $this->line('Test run enabled');
            $ready = $this->ask(
                'Please confirm that you have deleted all old migrations, except ones that change framework or package created tables (y|N)'
            );

            if ($ready != 'y') {
                $this->info('Aborting');

                return 0;
            }
        }

        $this->newLn();
        $this->line(sprintf('Reading structure information from connection %s, database %s', $this->defaultConnection, $this->databaseName));

        DB::setDefaultConnection('information_schema_mysql');

        $structure         = Meltor::getDatabaseStructure($this->databaseName);
        $uniqueKeys        = Meltor::getUniqueKeys($this->databaseName);
        $foreignKeys       = Meltor::getForeignKeys($this->databaseName);
        $innoDbIndexResult = Meltor::getIndexesFromInnoDb($this->databaseName);

        if (is_array($innoDbIndexResult)) {
            $this->canAccessInnoDbIndexes = true;
            $this->indexes                = $innoDbIndexResult;
        } else {
            $this->warn(sprintf('Could not read indexes via information_schema: %s', $innoDbIndexResult));
            $this->line('Instead reading indexes via Doctrine, this may change the order of the indexes within tables');
        }

        DB::setDefaultConnection($this->defaultConnection);

        $tableMigrations      = [];
        $constraintMigrations = [];

        foreach ($structure as $tableName => $columns) {
            $columnMigrations           = [];
            $columnConstraintMigrations = [];

            foreach ($columns->sortBy('ORDINAL_POSITION') as $column) {
                $columnMigrations[] = Meltor::generateColumnMigration($column);
            }

            foreach ($uniqueKeys->get($tableName) ?? [] as $uniqueKey) {
                $columnMigrations[] = Meltor::generateUniqueKeyMigration($uniqueKey);
            }

            foreach (Meltor::getIndexesFor($tableName, $this->canAccessInnoDbIndexes, $this->indexes) as $indexKeyName => $indexKeyColumns) {
                $columnMigrations[] = Meltor::generateIndexKeyMigration($indexKeyName, $indexKeyColumns);
            }

            foreach ($foreignKeys->get($tableName) ?? [] as $foreignKey) {
                $columnConstraintMigrations[] = Meltor::generateForeignKeyMigration($foreignKey);
            }

            $tableMigrations[$tableName]      = $columnMigrations;
            $constraintMigrations[$tableName] = $columnConstraintMigrations;
        }

        $migrationCode      = Meltor::generateMigration($tableMigrations, $constraintMigrations);
        $migrationCode      = Meltor::beautify($migrationCode);
        $migrationFilePath  = $this->writeMigration($migrationCode);
        $showDisclaimerText = true;

        if ($this->option('testrun')) {
            $showDisclaimerText = $this->testrun();
        }

        if ($showDisclaimerText) {
            $this->newLn();
            $this->line(
                sprintf('Test and inspect "%s" to make sure the migration fits your requirements', $migrationFilePath)
            );
            $this->line('You can then delete all prior migrations and commit');
            $this->warn('It may be necessary to keep migrations that alter tables created by frameworks or packages!');
        }

        $this->newLn();

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
        $success = false;

        $this->newLn();
        $this->line('Performing structure comparison test run');

        $this->newLn();
        $this->line('Backing up database');
        $this->call('protector:export', ['--file' => Meltor::getConfigValueForKey('testrun.backupFileName')]);

        $this->writeStructureDump($this->beforeStructureFileName);

        $this->newLn();
        $this->line('Running consolidated migration');
        $this->call('migrate:fresh', []);

        $this->writeStructureDump($this->afterStructureFileName);
        $this->restoreBackup();

        $this->line('Comparing structure dumps');

        $fileSizeBefore  = filesize($this->beforeStructureFileName);
        $fileSizeAfter   = filesize($this->afterStructureFileName);
        $beforeStructure = file_get_contents($this->beforeStructureFileName);
        $afterStructure  = file_get_contents($this->afterStructureFileName);
        $beforeStructure = Meltor::removeTimeStampFromDump($beforeStructure);
        $afterStructure  = Meltor::removeTimeStampFromDump($afterStructure);
        $beforeStructure = Meltor::removeRedundantCharSetFromDump($beforeStructure);
        $afterStructure  = Meltor::removeRedundantCharSetFromDump($afterStructure);

        $this->newLn();
        $this->line('Removing redundant timestamp and CHARACTER SET info from structure files');
        file_put_contents($this->beforeStructureFileName, $beforeStructure);
        file_put_contents($this->afterStructureFileName, $afterStructure);

        if (!$fileSizeBefore || !$fileSizeAfter) {
            $this->warn('At least one structure file is empty, that\'s not good!');
        } elseif ($beforeStructure == $afterStructure) {
            $this->info('Success! the structure files are identical!');
            $success = true;
        } else {
            $this->warn('The structure files are not identical, please check!');
            $success = true;
        }

        $this->line($this->beforeStructureFileName);

        if (!$fileSizeBefore) {
            $this->warn('Something went wrong, the before file is empty!');
        }

        $this->line($this->afterStructureFileName);

        if (!$fileSizeAfter) {
            $this->warn('Something went wrong, the after file is empty!');
        }

        return $success;
    }

    /**
     * Writes the new migration into the migrations folder.
     *
     * Older Meltor migrations will be removed
     * - in order to keep a current file name to run last
     * - while not filling the migrations folder with each run
     *
     * Will return the name of the new migration file.
     *
     * @param string $migrationCode
     *
     * @return string
     */
    protected function writeMigration(string $migrationCode): string
    {
        $migrationName       = 'meltor';
        $filename            = sprintf('%s_%s.php', (new DateTime())->format('Y_m_d_His'), $migrationName);
        $migrationFolder     = database_path('migrations');
        $migrationFilePath   = sprintf('%s/%s', $migrationFolder, $filename);
        $oldMigrationPattern = sprintf('%s/2022_??_??_??????_%s.php', $migrationFolder, $migrationName);

        foreach (glob($oldMigrationPattern) as $oldMeltorMigration) {
            $this->warn(sprintf('Removing old migration "%s"', $oldMeltorMigration));
            unlink($oldMeltorMigration);
        }

        $this->info(sprintf('Generate new migration "%s"', $migrationFilePath));
        file_put_contents($migrationFilePath, $migrationCode);

        return $migrationFilePath;
    }

    protected function restoreBackup()
    {
        $this->newLn();
        $this->line('Restoring database backup');
        $this->call('protector:import', ['--dump' => Meltor::getConfigValueForKey('testrun.backupFileName'), '--force' => true]);
        $this->newLn();
    }

    protected function writeStructureDump(string $fileName)
    {
        $this->newLn();
        $this->line(sprintf('Backing up database structure for comparison: %s', $fileName));
        @unlink($fileName);

        $connectionConfig = Meltor::getDatabaseConfig();
        $dumpOptions      = collect();
        $dumpOptions->push(sprintf('-h%s', escapeshellarg($connectionConfig['host'])));
        $dumpOptions->push(sprintf('-u%s', escapeshellarg($connectionConfig['username'])));
        $dumpOptions->push(sprintf('-p%s', escapeshellarg($connectionConfig['password'])));
        $dumpOptions->push('--no-data');
        $dumpOptions->push('--no-tablespaces');
        $dumpOptions->push(escapeshellarg($connectionConfig['database']));

        // See https://bugs.mysql.com/bug.php?id=20786
        exec(
            sprintf(
                'mysqldump %s | sed --expression="s/ AUTO_INCREMENT=[0-9]\+//" > %s 2> /dev/null',
                $dumpOptions->implode(' '),
                $fileName
            )
        );
    }

    /**
     * Prints a new line.
     * Exists for compatibility reasons.
     *
     * @return void
     */
    protected function newLn()
    {
        // To be replaced with $this->newLine()
        $this->line('');
    }
}
