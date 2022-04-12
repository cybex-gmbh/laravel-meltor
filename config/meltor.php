<?php

return [

    // The test run option applies the new migration, and compares the resulting DB structure
    'testrun' => [

        // This is where the database will be backup up and automatically restored from.
        'backupFileName'          => 'meltorTestrunBackup.sql',

        // The database structure files which will be the base of comparison.
        'beforeStructureFileName' => 'meltorStructureBefore.sql',
        'afterStructureFileName'  => 'meltorStructureAfter.sql',
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
            'bigint'  => 'bigInteger',
            'int'     => 'integer',
            'tinyint' => 'tinyInteger',
        ],

        // TODO: Refactor into a method using DB::class
        'uniqueKeysQuery'    => 'SELECT
                    stat.TABLE_SCHEMA AS database_name, stat.TABLE_NAME, stat.INDEX_NAME, GROUP_CONCAT(stat.COLUMN_NAME ORDER BY stat.seq_in_index separator ", ") AS columns, tco.CONSTRAINT_TYPE
                FROM information_schema.STATISTICS stat
                     JOIN information_schema.table_constraints tco
                      ON stat.TABLE_SCHEMA = tco.TABLE_SCHEMA
                          AND stat.TABLE_NAME = tco.TABLE_NAME
                          AND stat.INDEX_NAME = tco.CONSTRAINT_NAME

                WHERE stat.NON_UNIQUE = 0
                  AND stat.TABLE_SCHEMA = "%s"
                  AND tco.CONSTRAINT_TYPE = "UNIQUE"

                GROUP BY stat.TABLE_SCHEMA,
                         stat.TABLE_NAME,
                         stat.INDEX_NAME,
                         tco.CONSTRAINT_TYPE

                ORDER BY stat.TABLE_SCHEMA,
                         stat.TABLE_NAME;',

        // TODO: Refactor into a method using DB::class
        'foreignKeysQuery'   => 'SELECT TABLE_NAME,
                       COLUMN_NAME,
                       CONSTRAINT_NAME,
                       REFERENCED_TABLE_NAME,
                       REFERENCED_COLUMN_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE REFERENCED_TABLE_SCHEMA = "%s";',
    ],
];