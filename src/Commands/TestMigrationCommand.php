<?php

namespace Meltor\Commands;

use Exception;
use Illuminate\Console\Command;
use Meltor\Meltor;

class TestMigrationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'meltor:testmigration';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a test migration with all data types. Only for testing purposes.';

    protected ?Meltor $meltor;

    /**
     * Execute the console command.
     *
     * @return int
     * @throws Exception
     */
    public function handle(): int
    {
        $this->meltor     = app('meltor');
        $columnMigrations = [];

        foreach ($this->meltor->config('mysql.fluentDataTypes') as $mysqlType => $fluentType) {
            $column                     = new \stdClass();
            $column->COLUMN_NAME        = $mysqlType . '_field';
            $column->COLUMN_TYPE        = $mysqlType;
            $column->DATA_TYPE          = $mysqlType;
            $column->IS_NULLABLE        = false;
            $column->CHARACTER_SET_NAME = null;
            $column->COLLATION_NAME     = null;
            $column->COLUMN_COMMENT     = null;
            $column->EXTRA              = null;
            $column->COLUMN_DEFAULT     = null;

            $columnMigrations[] = $this->meltor->generateColumnMigration($column);
        }

        $tableMigrations['meltor_all_types_test'] = $columnMigrations;
        $migrationCode                            = $this->meltor->generateMigration($tableMigrations, 'Meltor test migration. Used for testing and development.');
        $migrationCode                            = $this->meltor->beautify($migrationCode);

        $this->newLine();
        $this->line('Meltor writes a migration file for a table with all mysql data types. Do not commit. Intended for testing purposes.');

        $this->meltor->writeMigration($migrationCode, 'meltor_all_types_test', $this);

        $this->newLine();

        return 0;
    }
}

