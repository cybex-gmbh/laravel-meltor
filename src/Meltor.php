<?php

namespace Meltor;

use DateTime;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Doctrine\DBAL\Schema\Index;

class Meltor
{
    /**
     * Returns a config value for a specific key and checks for Callables.
     *
     * @param string $key
     * @param null $default
     *
     * @return string|array|null
     */
    public function config(string $key, $default = null): string|array|null
    {
        $value = config(sprintf('meltor.%s', $key), $default);

        return is_callable($value) ? $value() : $value;
    }

    /**
     * Strips the leading database name from a (key) name pulled from the information schema.
     *
     * @param string $name
     *
     * @return string
     */
    public function stripDatabaseName(string $name): string
    {
        $strippedName = substr(strrchr($name, '/'), 1);

        return !$strippedName ? $name : $strippedName;
    }

    /**
     * Returns the default database config.
     *
     * @return array
     */
    public function getDatabaseConfig(): array
    {
        return config(
            sprintf(
                'database.connections.%s',
                config('database.default')
            ),
            false
        );
    }

    /**
     * Returns a list of tables and their columns for the given MySQL database.
     *
     * @param string $databaseName
     * @param ConnectionInterface $connection
     * @return Collection
     */
    public function getDatabaseStructure(string $databaseName, ConnectionInterface $connection): Collection
    {
        return $connection->table('COLUMNS')->where('TABLE_SCHEMA', $databaseName)->get()->groupBy('TABLE_NAME');
    }

    /**
     * Returns a list of unique keys for the given MySQL database.
     *
     * @param string $databaseName
     * @param ConnectionInterface $connection
     * @return Collection
     */
    public function getUniqueKeys(string $databaseName, ConnectionInterface $connection): Collection
    {
        $query = 'SELECT
                    stat.TABLE_SCHEMA AS database_name, 
                    stat.TABLE_NAME, 
                    stat.INDEX_NAME, 
                    GROUP_CONCAT(stat.COLUMN_NAME ORDER BY stat.seq_in_index separator ", ") AS columns, 
	                GROUP_CONCAT(IFNULL(stat.SUB_PART, "NULL") ORDER BY stat.seq_in_index separator ", ") AS lengths, 
                    constraints.CONSTRAINT_TYPE
                FROM information_schema.STATISTICS stat
                     JOIN information_schema.table_constraints constraints
                      ON stat.TABLE_SCHEMA = constraints.TABLE_SCHEMA
                          AND stat.TABLE_NAME = constraints.TABLE_NAME
                          AND stat.INDEX_NAME = constraints.CONSTRAINT_NAME

                WHERE stat.NON_UNIQUE = 0
                  AND stat.TABLE_SCHEMA = "%s"
                  AND constraints.CONSTRAINT_TYPE = "UNIQUE"

                GROUP BY stat.TABLE_SCHEMA,
                         stat.TABLE_NAME,
                         stat.INDEX_NAME,
                         constraints.CONSTRAINT_TYPE

                ORDER BY stat.TABLE_SCHEMA,
                         stat.TABLE_NAME;';

        return collect($connection->select(sprintf($query, $databaseName)))->groupBy('TABLE_NAME');
    }

    /**
     * Returns a list of foreign keys for the given MySQL database.
     *
     * @param string $databaseName
     * @param ConnectionInterface $connection
     * @return Collection
     */
    public function getForeignKeys(string $databaseName, ConnectionInterface $connection): Collection
    {
        $query = 'SELECT 
                    `usages`.`TABLE_NAME`,
                    `usages`.`COLUMN_NAME`,
                    `usages`.`CONSTRAINT_NAME`,
                    `usages`.`REFERENCED_TABLE_NAME`, 
                    `usages`.`REFERENCED_COLUMN_NAME`, 
                    `foreigns`.`TYPE`
                 FROM `INFORMATION_SCHEMA`.`KEY_COLUMN_USAGE` AS `usages` 
                 INNER JOIN `INNODB_FOREIGN` AS `foreigns` ON `foreigns`.`ID` = CONCAT(`usages`.`CONSTRAINT_SCHEMA`, "/", `usages`.`CONSTRAINT_NAME`) 
                 WHERE `usages`.`REFERENCED_TABLE_SCHEMA` = "%s";';

        return collect($connection->select(sprintf($query, $databaseName)))->groupBy('TABLE_NAME');
    }

    /**
     * Extract the MySQL DATA_TYPE column property and check it.
     *
     * @param object $column
     * @param bool $ignoreProblems
     * @return string|null
     * @throws Exception
     */
    protected function getDataType(object $column, bool $ignoreProblems = false): ?string
    {
        $columnType = $column->COLUMN_TYPE;
        $dataType   = $column->DATA_TYPE;

        if ($columnType == 'tinyint(1)') {
            return 'boolean';
        }

        if (!array_key_exists($dataType, $this->config('mysql.fluentDataTypes'))) {
            if (!$ignoreProblems) {
                throw new Exception(sprintf('unknown DATA_TYPE value "%s"', $dataType));
            }

            return null;
        }

        return $dataType;
    }

    /**
     * Extract the MySQL COLUMN_TYPE column property and check it.
     *
     * @param object $column
     * @param bool $ignoreProblems
     * @return string|null
     * @throws Exception
     */
    protected function getColumnType(object $column, bool $ignoreProblems = false): ?string
    {
        $columnType = $column->COLUMN_TYPE;
        $dataType   = $column->DATA_TYPE;

        if (!preg_match('/' . $dataType . '(?:\(\d+\))?(?:\sunsigned)?/', $columnType)) {
            if (!$ignoreProblems) {
                throw new Exception(sprintf('unknown COLUMN_TYPE value "%s"', $columnType));
            }

            return null;
        }

        return $columnType;
    }

    /**
     * Extract the MySQL EXTRA column property.
     *
     * The content is not being checked as there are multiple valid combinations.
     *
     * @param object $column
     *
     * @return string
     */
    protected function getExtra(object $column): string
    {
        return $column->EXTRA ?? '';
    }

    /**
     * Extracts the optional display width from the MySQL COLUMN_TYPE property.
     *
     * @param object $column
     * @param bool $ignoreProblems
     *
     * @return int|null
     * @throws Exception
     */
    protected function getDisplayWidth(object $column, bool $ignoreProblems = false): ?int
    {
        $columnType = $column->COLUMN_TYPE;
        $dataType   = $column->DATA_TYPE;

        // Not supporting display width for integer fields at this time.
        if (in_array($dataType, $this->config('mysql.fluentIntegerTypes'))) {
            return null;
        }

        $matches = [];

        preg_match('/^\w+\((\d+)\)$/', $columnType, $matches);

        if (count($matches) === 2) {
            $intMatch = (int)$matches[1];

            if ($intMatch != $matches[1]) {
                if (!$ignoreProblems) {
                    throw new Exception(
                        sprintf('unable to extract display width from COLUMN_TYPE value "%s"', $columnType)
                    );
                }

                return null;
            }

            return $intMatch;
        } elseif (count($matches) === 0) {
            return null;
        }

        throw new Exception(sprintf('unable to extract display width from COLUMN_TYPE value "%s"', $columnType));
    }

    /**
     * Returns the MySQL indexes of the analyzed database.
     *
     * Needs access to MySQL information schema internals.
     * There is an alternative way to retrieve this information in case this fails.
     *
     * @param string $databaseName
     * @param ConnectionInterface $connection
     * @param int $indexType
     * @return array|string
     */
    public function getIndexesFromInnoDb(string $databaseName, ConnectionInterface $connection, int $indexType): array|string
    {
        try {
            $dbIndexes = $connection->table('INNODB_TABLES AS table')
                ->select(
                    [
                        'table.NAME AS tableName',
                        'index.NAME AS indexName',
                        'field.NAME AS fieldName',
                        'field.POS AS fieldPosition',
                    ]
                )
                ->join('INNODB_INDEXES AS index', 'table.TABLE_ID', '=', 'index.TABLE_ID')
                ->join('INNODB_FIELDS AS field', 'index.INDEX_ID', '=', 'field.INDEX_ID')
                ->where('table.NAME', 'LIKE', sprintf('%s/%%', $databaseName))
                ->where('index.TYPE', '=', $indexType)
                ->get();

            $indexes = $dbIndexes->map(function ($item) {
                $item->tableName = $this->stripDatabaseName($item->tableName);

                return $item;
            })->groupBy(['tableName', 'indexName'])->toArray();
        } catch (QueryException $exception) {
            return $exception->getMessage();
        }

        return $indexes;
    }

    /**
     * Return the index information of a table.
     *
     * Based on Doctrine, used as a backup because it looses information on the index key order.
     *
     * @param string $tableName
     * @param $canAccessInnoDbIndexes
     * @param $indexes
     * @param ConnectionInterface $connection
     * @param bool $allowDoctrine
     * @return Collection
     */
    public function getIndexesFor(string $tableName, $canAccessInnoDbIndexes, $indexes, ConnectionInterface $connection, bool $allowDoctrine = false): Collection
    {
        if ($canAccessInnoDbIndexes) {
            $cachedIndexes = $indexes[$tableName] ?? [];
            $indexes       = collect();

            if (!$cachedIndexes) {
                return $indexes;
            }

            foreach ($cachedIndexes as $indexName => $indexFields) {
                $indexes->put(
                    $indexName,
                    collect($indexFields)->sortBy('fieldPosition')->pluck('fieldName')->toArray()
                );
            }

            return $indexes;
        }

        if (!$allowDoctrine) {
            return collect();
        }

        return collect(
            $connection->getDoctrineSchemaManager()->listTableIndexes($tableName)
        )->filter(fn($item) => !$item->isUnique() && !$item->isPrimary())->mapWithKeys(
            fn($item) => [$item->getName() => $item->getColumns()]
        );
    }

    /**
     * Return the primary key information of a table.
     *
     * Based on Doctrine.
     *
     * @param string $tableName
     * @param ConnectionInterface $connection
     * @return Index|null
     */
    public function getPrimaryKeyFor(string $tableName, ConnectionInterface $connection): ?Index
    {
        return collect($connection->getDoctrineSchemaManager()->listTableIndexes($tableName))->filter->isPrimary()->all()['primary'] ?? null;
    }

    /**
     * Returns a migration template.
     *
     * @param string $name
     * @param null $default
     *
     * @return string|null
     */
    public function getMigrationTemplate(string $name, $default = null): ?string
    {
        $value = config(sprintf('meltor-templates.%s', $name), $default);

        return is_callable($value) ? $value() : $value;
    }

    /**
     * Returns a laravel migration command to alter or create a field.
     *
     * @param string $method
     * @param string|null $columnsString
     * @param string|null $keyName
     * @return string
     */
    protected function compileColumnMigration(string $method, string $columnsString = null, string $keyName = null): string
    {
        $template = $this->getMigrationTemplate('column');

        if ($keyName) {
            return sprintf($template, sprintf('%s(%s, \'%s\')', $method, $columnsString, $keyName));
        }

        if ($columnsString) {
            return sprintf($template, sprintf('%s(%s)', $method, $columnsString));
        }

        return sprintf($template, $method);
    }

    /**
     * Returns a string for one or more fields for a migration command.
     *
     * @param array $columns
     * @return string
     */
    protected function compileColumnsString(array $columns): string
    {
        if (count($columns) > 1) {
            return sprintf('[\'%s\']', implode('\', \'', $columns));
        } else {
            return sprintf('\'%s\'', $columns[0]);
        }
    }

    /**
     * Returns a raw DB statement for MySQL COLUMN_TYPES that are not supported by Laravel.
     *
     * @param string $table
     * @param string $name
     * @param string $type
     * @return string
     */
    public function generateRawColumnMigration(string $table, string $name, string $type): string
    {
        return sprintf($this->getMigrationTemplate('columnRaw'), $table, $name, $type);
    }

    /**
     * Returns the content of a Laravel migration file.
     *
     * @param array $tableMigrations
     * @param string $comment
     * @param array $constraintMigrations
     *
     * @return string
     */
    public function generateMigration(array $tableMigrations, string $comment, array $constraintMigrations = [], array $rawExceptions = []): string
    {
        $tableMigrationCode = [];

        foreach ($tableMigrations as $tableName => $columnMigrations) {
            $columns              = implode("\n", $columnMigrations['laravel']);
            $columnsRaw           = count($columnMigrations['raw']) ? "\n" . implode("\n", $columnMigrations['raw']) : '';
            $tableMigrationCode[] = sprintf($this->getMigrationTemplate('createTable'), $tableName, $tableName, $columns, $columnsRaw);
        }

        $tables           = implode('', $tableMigrationCode);
        $constraintsTitle = '';
        $constraints      = '';

        if (count($constraintMigrations)) {
            $constraintsTitle        = '// Foreign Keys';
            $constraintMigrationCode = [];

            foreach ($constraintMigrations as $tableName => $columnConstraintMigrations) {
                if (count($columnConstraintMigrations)) {
                    $columns                   = implode("\n", $columnConstraintMigrations);
                    $constraintMigrationCode[] = sprintf($this->getMigrationTemplate('alterTable'), $tableName, $columns);
                }
            }

            $constraints = implode('', $constraintMigrationCode);
        }

        return sprintf(
            $this->getMigrationTemplate('migration'),
            (new DateTime())->format('Y-m-d H:i:s'),
            $comment,
            $tables,
            $constraintsTitle,
            $constraints
        );
    }

    /**
     * Describes one MySQL Column in Fluent.
     *
     * @param object $column
     * @param bool $ignoreProblems
     * @return string|null
     * @throws Exception
     */
    public function generateColumnMigration(object $column, bool $ignoreProblems = false): ?string
    {
        $columnName   = $column->COLUMN_NAME;
        $nullable     = $column->IS_NULLABLE === 'YES';
        $characterSet = $column->CHARACTER_SET_NAME;
        $collation    = $column->COLLATION_NAME;
        $comment      = $column->COLUMN_COMMENT;
        $dataType     = $this->getDataType($column, $ignoreProblems);

        // When the --ignoreProblems option is set, some data types are not being processed.
        if (is_null($dataType)) {
            return '';
        }

        $columnType    = $this->getColumnType($column, $ignoreProblems);
        $extra         = $this->getExtra($column);
        $unsigned      = str_contains($columnType, 'unsigned');
        $default       = $column->COLUMN_DEFAULT;
        $onUpdateTime  = str_contains($column->EXTRA, 'on update CURRENT_TIMESTAMP');
        $autoIncrement = $extra === 'auto_increment';
        $displayWidth  = $this->getDisplayWidth($column);
        $srsId         = $column->SRS_ID ?? null;
        $parts         = [];

        if ($columnName === 'id' && $dataType === 'bigint' && $unsigned && $autoIncrement) {
            return sprintf($this->compileColumnMigration('id()'));
        }

        if ($columnName === 'id' && $dataType === 'int' && $unsigned && $autoIncrement) {

            return sprintf($this->compileColumnMigration('increments', '\'id\''));
        }

        if ($srsId || $displayWidth) {
            $parts[] = sprintf('%s(\'%s\', %d)', $this->config('mysql.fluentDataTypes')[$dataType], $columnName, $srsId ?? $displayWidth);
        } else {
            $parts[] = sprintf('%s(\'%s\')', $this->config('mysql.fluentDataTypes')[$dataType], $columnName);
        }

        if ($unsigned) {
            $parts[] = 'unsigned()';
        }

        if ($characterSet) {
            $parts[] = sprintf('charset(\'%s\')', $characterSet);
        }

        if ($collation) {
            $parts[] = sprintf('collation(\'%s\')', $collation);
        }

        if (!is_null($default)) {
            if ($default === 'CURRENT_TIMESTAMP') {
                $parts[] = 'useCurrent()';
            } elseif (in_array($dataType, $this->config('mysql.fluentIntegerTypes'))) {
                $parts[] = sprintf('default(%d)', $default);
            } else {
                $parts[] = sprintf('default(\'%s\')', $default);
            }
        }

        if ($comment) {
            $parts[] = sprintf('comment(\'%s\')', $this->escapeComment($comment));
        }

        if ($nullable) {
            $parts[] = 'nullable()';
        }

        if ($onUpdateTime) {
            $parts[] = 'useCurrent()';
            $parts[] = 'useCurrentOnUpdate()';
        }

        return $this->compileColumnMigration(implode('->', $parts));
    }

    /**
     * Returns a unique key Laravel migration command.
     *
     * @param object $constraint
     * @param array $ignore
     * @param Command|null $command
     * @return string
     */
    public function generateUniqueKeyMigration(object $constraint, array $ignore = [], Command $command = null): string
    {
        $columnName = $constraint->INDEX_NAME;
        $columns    = explode(', ', $constraint->columns);
        $lengths    = explode(', ', $constraint->lengths);
        $problem    = false;

        $columnsWithLength = [];
        $tableName         = $constraint->TABLE_NAME;
        for ($i = 0; $i < count($columns); $i++) {
            $column = $columns[$i];
            if ($lengths[$i] !== 'NULL') {
                // Laravel doesn't support index length in migrations.
                $columnsWithLength[] = sprintf('DB::raw(\'%s(%d)\')', $column, $lengths[$i]);
            } else {
                $columnsWithLength[] = sprintf('\'%s\'', $column);
            }

            if (in_array($column, $ignore[$tableName] ?? [])) {
                $problem = true;
            }
        }

        $columnsString      = sprintf('[%s]', implode(', ', $columnsWithLength));
        $uniqueKeyMigration = $this->compileColumnMigration('unique', $columnsString, $columnName);

        if ($problem) {
            $uniqueKeyMigration = '// ' . $uniqueKeyMigration;
            $command?->warn(sprintf('Unique index "%s" left commented due to an unprocessed column in table "%s"', $columnName, $tableName));
        }

        return $uniqueKeyMigration;
    }

    /**
     * Returns an index key Laravel migration command.
     *
     * @param string $keyName
     * @param array $columns
     * @param bool $isSpatialIndex
     * @return string
     */
    public function generateIndexKeyMigration(string $keyName, array $columns, bool $isSpatialIndex = false): string
    {
        $columnsString = $this->compileColumnsString($columns);
        $methodName    = $isSpatialIndex ? 'spatialIndex' : 'index';

        return $this->compileColumnMigration($methodName, $columnsString, $keyName);
    }

    /**
     * Returns a primary key Laravel migration command.
     *
     * @param Index $primaryKey
     * @return string
     */
    public function generatePrimaryKeyMigration(Index $primaryKey): string
    {
        $columns = $primaryKey->getColumns();

        $columnsString = $this->compileColumnsString($columns);

        return $this->compileColumnMigration('primary', $columnsString);
    }

    /**
     * Returns a foreign key Laravel migration command.
     *
     * @param Collection $foreignKeyCollection
     * @param bool $ignoreProblems
     * @param Command|null $command
     * @return string
     * @throws Exception
     */
    public function generateForeignKeyMigration(Collection $foreignKeyCollection, bool $ignoreProblems = false, Command $command = null): string
    {
        $columns           = $foreignKeyCollection->pluck('COLUMN_NAME')->toArray();
        $referencedColumns = $foreignKeyCollection->pluck('REFERENCED_COLUMN_NAME')->toArray();
        $referencedTable   = $foreignKeyCollection->first()->REFERENCED_TABLE_NAME;
        $keyName           = $foreignKeyCollection->first()->CONSTRAINT_NAME;
        $keyType           = $foreignKeyCollection->first()->TYPE;
        $parts             = [];
        $parts[]           = sprintf('foreign(%s, \'%s\')', $this->compileColumnsString($columns), $keyName);
        $parts[]           = sprintf('references(%s)', $this->compileColumnsString($referencedColumns));
        $parts[]           = sprintf('on(\'%s\')', $referencedTable);
        $binaryChecksum    = 0;
        $binaryTypes       = [
            1  => 'cascadeOnDelete()',
            2  => 'nullOnDelete()',
            4  => 'cascadeOnUpdate()',
            8  => 'onDelete(\'SET NULL\')',
            // ON DELETE NO ACTION (ignored)
            16 => null,
            // ON UPDATE NO ACTION (ignored)
            32 => null,
        ];

        foreach ($binaryTypes as $binary => $laravelPart) {
            if ($keyType & $binary) {
                if (!is_null($laravelPart)) {
                    $parts[] = $laravelPart;
                }

                $binaryChecksum += $binary;
            }
        }

        if ($keyType != $binaryChecksum) {
            $message = sprintf('Could not determine binary key type "%s" for the key "%s"', $keyType, $keyName);
            if ($ignoreProblems) {
                $command?->warn($message);
            } else {
                throw new Exception($message);
            }
        }

        return $this->compileColumnMigration(implode('->', $parts));
    }

    /**
     * Consolidates according consecutive timestamp fields into timestamps().
     *
     * @param string $migrationCode
     *
     * @return string
     */
    public function beautify(string $migrationCode): string
    {
        $modifications = [
            // Consolidate consecutive creation and update timestamps into timestamps().
            '/\$table->timestamp\(\'created_at\'\)->nullable\(\);\s+\$table->timestamp\(\'updated_at\'\)->nullable\(\);/' => '$table->timestamps();',

            // Consolidate various integer() kinds followed by unsigned() with the according decorative method.
            '/\$table->bigInteger\(\'([^\']+)\'\)->unsigned\(\)/'                                                         => '$table->unsignedBigInteger(\'$1\')',
            '/\$table->integer\(\'([^\']+)\'\)->unsigned\(\)/'                                                            => '$table->unsignedInteger(\'$1\')',
            '/\$table->tinyInteger\(\'([^\']+)\'\)->unsigned\(\)/'                                                        => '$table->unsignedTinyInteger(\'$1\')',

            // Remove the optional array braces when unique() only has one database field.
            '/\$table->unique\(\[\'(\\w+)\'\], \'(\\w+)\'\);/'                                                            => '$table->unique(\'$1\', \'$2\');',
        ];

        foreach ($modifications as $search => $replace) {
            $migrationCode = preg_replace($search, $replace, $migrationCode);
        }

        return $migrationCode;
    }

    /**
     * Combine a filename with a path.
     * Used when methods like storage_path() are not flexible enough.
     *
     * @param string $folder
     * @param string $file
     * @return string
     */
    public function combinePath(string $folder, string $file): string
    {
        return sprintf('%s/%s', $folder, $file);
    }

    /**
     * Remove redundant CHARACTER SET definitions from a given structure dump to allow comparison.
     * Character sets may be defined for a column, but not being extracted when they're identical to the table definition.
     *
     * @param string $sqlStructure
     *
     * @return string
     */
    protected function removeRedundantCharSetFromDump(string $sqlStructure): string
    {
        return preg_replace('/CHARACTER SET (\w+)[\s,](?=[^;]+DEFAULT CHARSET=\1\s[^;]+;)/', '', $sqlStructure);
    }

    /**
     * Remove generation timestamp from the given structure dump to allow comparison.
     *
     * @param string $sqlStructure
     *
     * @return string
     */
    protected function removeTimeStampFromDump(string $sqlStructure): string
    {
        // The time format in mysqldump file footers doesn't pad its zeroes according to ISO 8601, so trying to guess the format.
        return preg_replace(
            '/Dump completed on [\d\-\s:]+/',
            'Dump completed on (removed for comparison)',
            $sqlStructure
        );
    }

    /**
     * Reads a given database structure file for comparison, and removes incomparable noise from the content.
     *
     * The resulting content is only suitable for comparison.
     *
     * @param string $structureFileName
     *
     * @return string
     */
    public function readAndCleanStructure(string $structureFileName): string
    {
        $structure = file_get_contents($structureFileName);
        $structure = $this->removeTimeStampFromDump($structure);
        $structure = $this->removeRedundantCharSetFromDump($structure);

        return $structure;
    }

    /**
     * Writes a new migration into the "migrations" folder.
     *
     * Older migrations with the same name will be removed
     * - in order to keep a current file name to run last
     * - while not filling the "migrations" folder with each run
     *
     * Will return the name of the new migration file.
     *
     * @param string $migrationContent
     * @param string $migrationName
     * @param int $offset Seconds to add to the file name date
     * @param Command|null $command Used for shell output
     *
     * @return string
     */
    public function writeMigration(string $migrationContent, string $migrationName, Command $command = null, int $offset = 0): string
    {
        $filename          = sprintf('%s_%s.php', now()->addSeconds($offset)->format('Y_m_d_His'), $migrationName);
        $migrationFilePath = $this->combinePath($this->config('migration.folder'), $filename);

        $this->removeMigrations($migrationName, $command);
        $command?->info(sprintf('Generate new migration "%s"', $migrationFilePath));
        file_put_contents($migrationFilePath, $migrationContent);

        return $migrationFilePath;
    }

    /**
     * Removes previously created migrations of a given name.
     *
     * @param string $migrationName
     * @param Command|null $command Used for shell output
     * @return void
     */
    public function removeMigrations(string $migrationName, Command $command = null): void
    {
        $migrationFolder     = $this->config('migration.folder');
        $oldMigrationPattern = sprintf('%s/????_??_??_??????_%s.php', $migrationFolder, $migrationName);

        foreach (File::glob($oldMigrationPattern) as $oldMeltorMigration) {
            $command?->warn(sprintf('Removing old migration "%s"', $oldMeltorMigration));
            File::delete($oldMeltorMigration);
        }
    }

    /**
     * Restore the database to its prior state.
     *
     * To be used if something goes wrong during a testrun, where the database is being modified for comparison.
     *
     * @param Command|null $command Used for shell output
     * @return void
     */
    public function restoreBackup(Command $command = null): void
    {
        $command?->newLine();
        $command?->line('Restoring database backup');
        $command?->call('protector:import', ['--dump' => $this->config('testrun.backupFileName'), '--force' => true]);
        $command?->newLine();
    }

    /**
     * Write a slightly simplified MySQL dump, intended only for structure comparisons.
     *
     * Does not contain data, excludes certain tables.
     *
     * @param string $fileName
     * @param Command|null $command Used for shell output
     * @return void
     */
    public function writeStructureDump(string $fileName, Command $command = null): void
    {
        $command?->newLine();
        $command?->line(sprintf('Backing up database structure for comparison: %s', $fileName));
        @unlink($fileName);

        $connectionConfig = $this->getDatabaseConfig();
        $dumpOptions      = collect();
        $dumpOptions->push(sprintf('-h%s', escapeshellarg($connectionConfig['host'])));
        $dumpOptions->push(sprintf('-u%s', escapeshellarg($connectionConfig['username'])));
        $dumpOptions->push(sprintf('-p%s', escapeshellarg($connectionConfig['password'])));
        $dumpOptions->push('--no-data');
        $dumpOptions->push('--no-tablespaces');

        foreach ($this->config('testrun.excludedTables') ?? [] as $excludedTable) {
            $dumpOptions->push(escapeshellarg(sprintf('--ignore-table=%s.%s', $connectionConfig['database'], trim($excludedTable))));
        }

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
     * Escape the string that is written into the column comment in the new, generated migration.
     *
     * @param $value
     * @return array|string
     */
    function escapeComment($value): array|string
    {
        $search  = ["\\", "\x00", "\n", "\r", "'", '"', "\x1a"];
        $replace = ["\\\\", "\\0", "\\n", "\\r", "\'", '\"', "\\Z"];

        return str_replace($search, $replace, $value);
    }
}
