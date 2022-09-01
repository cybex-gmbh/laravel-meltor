<?php

namespace Meltor\Tests;

use Exception;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use PDO;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

class MeltorTests extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupDb();
    }

    /**
     * @test
     * @return void
     */
    public function canReadConfig(): void
    {
        Config::set('meltor.string', 'stringResult');
        Config::set('meltor.subkey.string', 'stringResult');

        $this->assertEquals(app('meltor')->config('string'), 'stringResult');
        $this->assertEquals(app('meltor')->config('subkey.string'), 'stringResult');

        Config::set('meltor.closure', fn() => 'closureResult');
        Config::set('meltor.subkey.closure', fn() => 'closureResult');

        $this->assertEquals(app('meltor')->config('closure'), 'closureResult');
        $this->assertEquals(app('meltor')->config('subkey.closure'), 'closureResult');
    }

    /**
     * @test
     * @return void
     */
    public function canStripDatabaseName(): void
    {
        $this->assertEquals(app('meltor')->stripDatabaseName('database_name/table_name'), 'table_name');
        $this->assertEquals(app('meltor')->stripDatabaseName('table_name'), 'table_name');
    }

    /**
     * @test
     * @return void
     */
    public function canGetDatabaseConfigOfProject(): void
    {
        $this->assertTrue(array_key_exists('driver', app('meltor')->getDatabaseConfig()));
    }


    /**
     * @test
     * @return void
     */
    public function canGetDatabaseStructure(): void
    {
        $result = app('meltor')->getDatabaseStructure('meltor', app('db')->connection('information_schema'));

        $this->assertArrayHasKey('migrations', $result);
    }

    /**
     * @test
     * @return void
     */
    public function canGetUniqueKeys(): void
    {
        $result = app('meltor')->getUniqueKeys('meltor', app('db')->connection('information_schema'));

        $this->assertArrayHasKey('meltor_all_types_test', $result);
        $this->assertEquals('unique_one_field', $result['meltor_all_types_test'][0]->INDEX_NAME);
    }

    /**
     * @test
     * @return void
     */
    public function canGetForeignKeys(): void
    {
        $result = app('meltor')->getForeignKeys('meltor', app('db')->connection('information_schema'));

        $this->assertArrayHasKey('meltor_all_types_test', $result);
        $this->assertEquals('meltor_foreign_id', $result['meltor_all_types_test'][0]->COLUMN_NAME);
    }

    /**
     * @test
     * @return void
     */
    public function canGetDataTypeFromColumn(): void
    {
        $validColumn           = $this->getDummyColumn();
        $validBoolColumn       = $this->getDummyColumn('int', 'tinyint(1)');
        $invalidFileTypeColumn = $this->getDummyColumn('int', 'int', 'unknown type');

        // Special boolean handling
        $this->assertEquals('boolean', $this->runProtectedMethod('getDataType', [$validBoolColumn]));

        // Regular handling
        $this->assertEquals('int', $this->runProtectedMethod('getDataType', [$validColumn]));

        // Invalid MySQL DATA_TYPE, ignore problems mode
        $this->assertNull($this->runProtectedMethod('getDataType', [$invalidFileTypeColumn, true]));

        // Invalid MySQL DATA_TYPE
        $this->expectException(Exception::class);
        $this->runProtectedMethod('getDataType', [$invalidFileTypeColumn]);
    }

    /**
     * @test
     * @return void
     */
    public function canGetExtraFromColumn(): void
    {
        $validColumn              = $this->getDummyColumn();
        $validAutoIncrementColumn = $this->getDummyColumn('int', 'int', 'int', 'auto_increment');

        $this->assertEquals('auto_increment', $this->runProtectedMethod('getExtra', [$validAutoIncrementColumn]));
        $this->assertEquals('', $this->runProtectedMethod('getExtra', [$validColumn]));
    }

    /**
     * @test
     * @return void
     */
    public function canGetColumnType(): void
    {
        $validColumn       = $this->getDummyColumn();
        $invalidTypeColumn = $this->getDummyColumn('int', 'foo');

        $this->assertEquals('int', $this->runProtectedMethod('getColumnType', [$validColumn]));

        // Invalid MySQL COLUMN_TYPE, problems ignored
        $this->assertNull($this->runProtectedMethod('getColumnType', [$invalidTypeColumn, true]));

        // Invalid MySQL COLUMN_TYPE
        $this->expectException(Exception::class);
        $this->runProtectedMethod('getColumnType', [$invalidTypeColumn]);
    }

    /**
     * @test
     * @return void
     */
    public function canGetDisplayWidth(): void
    {
        $validColumn       = $this->getDummyColumn();
        $invalidTypeColumn = $this->getDummyColumn('int', 'foo');

        $this->assertEquals('int', $this->runProtectedMethod('getColumnType', [$validColumn]));

        // Invalid MySQL COLUMN_TYPE, problems ignored
        $this->assertNull($this->runProtectedMethod('getColumnType', [$invalidTypeColumn, true]));

        // Invalid MySQL COLUMN_TYPE
        $this->expectException(Exception::class);
        $this->runProtectedMethod('getColumnType', [$invalidTypeColumn]);
    }


    // =====================================================================


    protected function setupDb()
    {
        Config::set('database.default', 'mysql');

        Config::set(
            'database.connections.mysql',
            [
                'driver'         => 'mysql',
                'url'            => null,
                'host'           => 'laravel-meltor-mysql-1',
                'port'           => '3306',
                'database'       => 'meltor',
                'username'       => 'homestead',
                'password'       => 'secret',
                'unix_socket'    => '',
                'charset'        => 'utf8mb4',
                'collation'      => 'utf8mb4_unicode_ci',
                'prefix'         => '',
                'prefix_indexes' => true,
                'strict'         => true,
                'engine'         => null,
                'options'        => extension_loaded('pdo_mysql') ? array_filter(
                    [
                        PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
                    ]
                ) : [],
            ],
        );

        Config::set(
            'database.connections.information_schema',
            [
                'driver'         => 'mysql',
                'url'            => null,
                'host'           => 'laravel-meltor-mysql-1',
                'port'           => '3306',
                'database'       => 'information_schema',
                'username'       => 'root',
                'password'       => 'secret',
                'unix_socket'    => '',
                'charset'        => 'utf8mb4',
                'collation'      => 'utf8mb4_unicode_ci',
                'prefix'         => '',
                'prefix_indexes' => true,
                'strict'         => true,
                'engine'         => null,
                'options'        => extension_loaded('pdo_mysql') ? array_filter(
                    [
                        PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
                    ]
                ) : [],
            ],
        );

        Artisan::call('migrate:fresh');

        if (!Schema::hasTable('meltor_foreigns')) {
            Schema::create('meltor_foreigns', function (Blueprint $table) {
                $table->id();
            });
        }

        if (!Schema::hasTable('meltor_all_types_test')) {
            Schema::create('meltor_all_types_test', function (Blueprint $table) {
                $table->tinyInteger('tinyint_field');
                $table->boolean('boolean_field');
                $table->bigInteger('bigint_field');
                $table->mediumInteger('mediumint_field');
                $table->smallInteger('smallint_field');
                $table->integer('int_field');
                $table->decimal('decimal_field');
                $table->float('float_field');
                $table->double('double_field');
                $table->date('date_field');
                $table->timestamp('timestamp_field');
                $table->dateTime('datetime_field');
                $table->time('time_field');
                $table->year('year_field');
                $table->char('char_field');
                $table->string('varchar_field');
                $table->tinyText('tinytext_field');
                $table->mediumText('mediumtext_field');
                $table->text('text_field');
                $table->longText('longtext_field');
                $table->binary('blob_field');
                $table->geometry('geometry_field');
                $table->point('point_field');
                $table->lineString('linestring_field');
                $table->polygon('polygon_field');
                $table->multiPoint('multipoint_field');
                $table->multiLineString('multilinestring_field');
                $table->multiPolygon('multipolygon_field');
                $table->json('json_field');
                $table->unique('int_field', 'unique_one_field');
                $table->foreignId('meltor_foreign_id')->constrained();
            });
        }
    }

    /**
     * @param $method
     *
     * @return ReflectionMethod
     */
    protected function getAccessibleReflectionMethod($method): ReflectionMethod
    {
        $reflectionProtector = new ReflectionClass(app('meltor'));
        $method              = $reflectionProtector->getMethod($method);

        $method->setAccessible(true);

        return $method;
    }

    /**
     * Allows a test to call a protected method.
     *
     * @param string $methodName
     * @param array $params
     *
     * @return mixed
     */
    protected function runProtectedMethod(string $methodName, array $params)
    {
        $method = $this->getAccessibleReflectionMethod($methodName);
        return $method->invoke(app('meltor'), ...$params);
    }

    protected function getDummyColumn(string $mysqlType = 'int', string $columnType = 'int', string $dataType = 'int', string $extra = ''): stdClass
    {
        $column                     = new \stdClass();
        $column->COLUMN_NAME        = $mysqlType . '_field';
        $column->COLUMN_TYPE        = $columnType;
        $column->DATA_TYPE          = $dataType;
        $column->IS_NULLABLE        = false;
        $column->CHARACTER_SET_NAME = null;
        $column->COLLATION_NAME     = null;
        $column->COLUMN_COMMENT     = null;
        $column->EXTRA              = $extra;
        $column->COLUMN_DEFAULT     = null;

        return $column;
    }
}