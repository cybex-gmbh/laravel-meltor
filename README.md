# Laravel Meltor

[![Latest Version on Packagist](https://img.shields.io/packagist/v/cybex/laravel-meltor.svg?style=flat-square)](https://packagist.org/packages/cybex/laravel-meltor)
[![Total Downloads](https://img.shields.io/packagist/dt/cybex/laravel-meltor.svg?style=flat-square)](https://packagist.org/packages/cybex/laravel-meltor)
![GitHub Actions](https://github.com/cybex-gmbh/laravel-meltor/actions/workflows/main.yml/badge.svg)

Attempts to consolidate all prior migrations into a single, new one. This is entirely based on the local MySQL database state,
and not the prior migration files!

This can make sense when
- migrations need to be removed because they are no longer compatible with your codebase
- the amount of migration files slows down your testing workflow
- changes in migrations are hard to look up anyway because tables get changed many times

Before you start

- Make sure that you have put your local MySQL DB into the correct state!
- Disable all development packages that change the structure of your database!

When you have reviewed, tested and possibly fixed missing details of the new migration, you can commit the new migration and the deletion of the old migrations.
You may need to keep migrations which alter tables created by the framework or packages.

## Installation

You can install the package via composer:

```bash
composer require cybex/laravel-meltor
```

### Access to the MySQL information schema

Add a new connection to `config/database.php` under the `connections` key, so that it is accessible with `config('database.connections.information_schema_mysql')`. 
It can be based on your regular connection, with just the database set to `information_schema`. 

For example
```
  // Information schema connection for the laravel-meltor package
  'information_schema_mysql' => [
      'driver'      => 'mysql',
      'host'        => env('TEST_DB_HOST', env('DB_HOST', '127.0.0.1')),
      'port'        => env('TEST_DB_PORT', env('DB_PORT', '3306')),
      'database'    => 'information_schema',
      'username'    => env('DB_USERNAME', 'forge'),
      'password'    => env('DB_PASSWORD', ''),
      'unix_socket' => env('DB_SOCKET', ''),
      'charset'     => 'utf8mb4',
      'collation'   => 'utf8mb4_unicode_ci',
      'prefix'      => '',
      'strict'      => true,
      'engine'      => null,
  ],
```

As this package intended to generate a new migration on a non-production system, there may be no need to commit this new connection.

#### Permissions

Make sure that your local database user has sufficient permissions to access all tables of the mysql information_schema db.
If the permissions are insufficient, this package will use Doctrine through PHP, instead of reading from the according mysql information_schema tables.
The fallback to Doctrine leads to the following deviations in the new, generated migration:

- Spatial indexes will be left out
- The index order within tables may differ

Configuring the root user to access the database with sufficient permissions is possible, but generally not recommended for multiple reasons.


## Usage

To create a new migration file:

```php
php artisan meltor:generate
```

Notes:

- You may need to give your mysql user additional permissions. It is possible, but generally not recommended to use the root user.
- You may need to keep migrations which alter tables created by the framework or packages
- Laravel will create a DOUBLE instead of a FLOAT when using Blueprint's $table->float()

### Test run

To also do a comparison between the old and the new database:

```php
php artisan meltor:generate --testrun
```

Notes:

- Delete all old migrations before starting the test run
- This command will need to modify your local database, but will also restore it afterwards

### Recovery

This package uses the laravel-protector package to back up your database during the test run.
The backup file is in the default protector folder, by default `storage/app/protector/meltorTestrunBackup.sql`.

In case the `artisan meltor:generate --testrun` command has crashed, you can restore the previous DB state:
```php
php artisan meltor:generate --restore
```

### Problems

This package will abort when it cannot replicate the database into a migration file. If you want to proceed anyway, use the `--ignoreProblems` option.
There will be warning messages informing you about table columns and indexes that have not been transferred.

#### Foreign keys

The db statement `FOREIGN_KEY_CHECKS=0` that is automatically added to the resulting migration file should make it possible to add all foreign keys 
right when creating a new table. This also makes the migration easier to read because everything is in one place.
Should this fail to work with your version of MySQL, or should you prefer not to use this db statement, you can use the `--separateForeignKeys` to move all foreign 
key declarations to the end of the migration file, which separates the table declarations and their order from the dependencies and the order in which foreign keys
have to be created.

## Security

If you discover any security related issues, please email webdevelopment@cybex-online.com instead of using the issue
tracker.

## Style

The code style is based on PSR-12

Exceptions: 
- Align consecutive assignments
- Arrays: align key-value pairs
- Hard Wrap at: 180

## Credits

- [Cybex Web Development Team](https://github.com/cybex-gmbh)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
