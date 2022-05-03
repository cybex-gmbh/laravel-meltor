<?php

use Illuminate\Support\Facades\DB;

return [

    // Names of the Laravel connections, used as the base for the new, generated migration.
    'connection' => [

        // Connection to read the tables.
        'data'   => fn() => DB::getDefaultConnection(),

        // You may need to create a new DB connection that points to the information_schema. See README.md.
        'schema' => 'information_schema_mysql',
    ],

    'migration' => [

        // Filename of the new, generated migration.
        'name' => 'meltor',

        // Folder where the migrations will be placed.
        'folder' => fn() => database_path('migrations'),
    ],

    // The test run option applies the new migration, and compares the resulting DB structure
    'testrun' => [

        // This is where the database will be backup up and automatically restored from.
        'backupFileName'          => 'meltorTestrunBackup.sql',

        // The database structure files which will be the base of comparison.
        'beforeStructureFileName' => 'meltorStructureBefore.sql',
        'afterStructureFileName'  => 'meltorStructureAfter.sql',

        // Folder where the comparison files will be placed.
        'folder' => fn() => storage_path(),

        // Tables excluded from comparison.
        'excludedTables' => [
            'migrations',
            'job_batches',
            'personal_access_tokens',
        ],
    ],

    // MySQL data types are being converted to Laravel fluent ones.
    'mysql'   => [
        // https://laravel.com/docs/9.x/migrations#available-column-types
        'fluentDataTypes' => [
            'bigint'     => 'bigInteger',
            // Note that tinyint(1) is being handled separately.
            'boolean'    => 'boolean',
            'char'       => 'char',
            'date'       => 'date',
            'int'        => 'integer',
            'json'       => 'json',
            'longtext'   => 'longText',
            'mediumtext' => 'mediumText',
            'text'       => 'text',
            'time'       => 'time',
            'timestamp'  => 'timestamp',
            'tinyint'    => 'tinyInteger',
            'varchar'    => 'string',
        ],

        'fluentIntegerTypes' => [
            'bigint',
            'int',
            'tinyint',
        ],
    ],
];