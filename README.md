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

In case the `artisan meltor:generate --testrun` command has crashed, the next time you call the command, it will ask if you want to restore the database.

You can also manually restore the backup into the database, which will also remove the backup file, with:
```php
php artisan meltor:restore
```

### Customizing file paths

The following .env keys allow you to control where the temporary test run files will be stored:

This is where the database will be backed up and automatically restored from. The disk is controlled by the laravel-protector package.
```
MELTOR_BACKUP_FILENAME=meltorTestrunBackup.sql
```

Disk where the comparison files will be placed.
```
MELTOR_COMPARISON_DISK=local
```

Folder where the comparison files will be placed.
```
MELTOR_COMPARISON_FOLDER=
```

The database structure files which will be the base of comparison.
```
MELTOR_COMPARISON_BEFORE_FILENAME=meltorStructureBefore.sql
MELTOR_COMPARISON_AFTER_FILENAME=meltorStructureAfter.sql
```

### Problems

This package will abort when it cannot replicate the database into a migration file. If you want to proceed anyway, use the `--ignoreProblems` option.
There will be warning messages informing you about table columns and indexes that have not been transferred.

Note that calling `artisan meltor:generate` will remove older migration files that it generated. If you want to change the generated migration file,
be sure to rename the file to not contain `meltor` (customizable in `config('meltor.migration.name')`) and/or commit your changes before running the command again. 

#### Foreign keys

The db statement `FOREIGN_KEY_CHECKS=0` that is automatically added to the resulting migration file should make it possible to add all foreign keys 
right when creating a new table. This also makes the migration easier to read because everything is in one place.
Should this fail to work with your version of MySQL, or should you prefer not to use this db statement, you can use the `--separateForeignKeys` to move all foreign 
key declarations to the end of the migration file, which separates the table declarations and their order from the dependencies and the order in which foreign keys
have to be created.

#### Access to the MySQL information schema

By default, this package uses the credentials of the default "mysql" laravel database connection to access the "information_schema" MySQL database.
This is required in order to read out information about indices.

You can configure a different database connection name in the meltor configuration file, which may be published with `php artisan vendor:publish`

If the permissions of the database credentials do not allow access to all tables of the mysql information_schema db, this package will use Doctrine through PHP,
instead of reading from the according mysql information_schema tables.

The fallback to Doctrine leads to the following deviations in the new, generated migration:

- Spatial indexes will be left out
- The index order within tables may differ

Configuring the root user to access the database with sufficient permissions is possible, but generally not recommended for multiple reasons.

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
