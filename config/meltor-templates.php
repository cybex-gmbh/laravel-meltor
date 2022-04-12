<?php

return [

    // These templates are the base for the generated migration file.

    // The main migration file.
    'migration' => '<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * This Migration sums up the database structure for all past Migrations up to %s.
     *
     * On migrate:fresh, framework and package tables will not be changed if they exist when this runs!
     * Be sure to keep migration files which alter these!
     *
     * @return void
     */
    public function up()
    {
        // Tables:
        %s


        // Foreign Keys:
        %s
    }
};
',

    // Table creation.
    'createTable' => '
        if (!Schema::hasTable(\'%s\')) {
            Schema::create(\'%s\', function (Blueprint $table) {
%s
            });
        }
',

    // Table alteration for foreign keys.
    'alterTable' => '
        Schema::table(\'%s\', function (Blueprint $table) {
%s
        });
',

    'column' => '                $table->%s;',
];