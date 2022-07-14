<?php

return [

    // These templates are the base for the generated migration file.

    'migrationComment' => 'This Migration sums up the database structure for all past Migrations.
     *
     * On migrate:fresh, framework and package tables will not be changed if they exist when this runs!
     * Be sure to keep migration files which alter these!',

    // The main migration file.
    'migration' => '<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * %s: %s
     *
     * @return void
     */
    public function up()
    {
        DB::statement(\'/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\');
        DB::statement(\'/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE="NO_AUTO_VALUE_ON_ZERO" */;\');
    
        // Tables:
        %s

        %s
        %s
        
        DB::statement(\'/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, "") */;\');
        DB::statement(\'/*!40014 SET FOREIGN_KEY_CHECKS=IF(@OLD_FOREIGN_KEY_CHECKS IS NULL, 1, @OLD_FOREIGN_KEY_CHECKS) */;\');
    }
};
',

    // Table creation. Optionally there can be raw statements after the laravel method call, for MySQL DATA_TYPES Laravel doesn't support.
    'createTable' => '
        if (!Schema::hasTable(\'%s\')) {
            Schema::create(\'%s\', function (Blueprint $table) {
%s
            });%s
        }
',

    // Table alteration for foreign keys.
    'alterTable' => '
        Schema::table(\'%s\', function (Blueprint $table) {
%s
        });
',

    // Single column in a table.
    'column' => '                $table->%s;',

    // Single column as raw statement for MySQL DATA_TYPES not supported by Laravel.
    'columnRaw' =>  '            DB::statement(\'ALTER TABLE `%s` ADD `%s` %s\');',
];
