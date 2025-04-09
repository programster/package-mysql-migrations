<?php

/**
 * This is a class to handle database migrations or one-time scripts
 * For the time being, this object takes a raw mysqli connection, so it has complete flexibility
 * when manipulating mysqli databases. A database driver may be better for when we can simply run
 * "create table", add column etc.
 *
 * This script relies on a table called 'migrations' (by default) or any other user defined, in the
 * database for version info. If it does not yet exist, then it will be created and the database
 * will be considered to be at version 0
 */

namespace Programster\MysqlMigrations;

use Exception;
use mysqli;

class MigrationManager
{
    private readonly string $m_escapedTableName;
    private mysqli $m_mysqliConn; #  A mysqli connection object that will be used to manipulate the db.
    private readonly string $m_migrationsFolder; # The folder in which migration scripts are located.


    /**
     * Creates the Migration object in preparation for migration.
     * @param string $migrationsFolder the path to the folder containing all the migration scripts
     * this may be absolute or relative.
     * @param mysqli $connection a Mysqli object connecting us to the database.
     * @param string $table table name for version info
     */
    public function __construct(string $migrationsFolder, mysqli $connection, string $table = 'migrations')
    {
        $this->m_migrationsFolder = $migrationsFolder;
        $this->m_mysqliConn = $connection;
        $this->m_escapedTableName = $connection->escape_string($table);
    }


    /**
     * Migrates the database to the specified version. If the version is not specified (null) then this will
     * automatically migrate the database to the furthest point which is determined by looking at the schemas.
     * @param int|null $desiredVersion - optional parameter to specify the version we wish to migrate to. If not set,
     * then this will automatically migrate to the latest version which is discovered by looking at the files.
     * @return void
     * @throws Exception
     */
    public function migrate(int|null $desiredVersion = null): void
    {
        $databaseVersion = $this->getDbVersion();
        $migrationFiles = $this->getMigrationFiles();

        if ($desiredVersion === null)
        {
            end($migrationFiles); # move the internal pointer to the end of the array
            $desiredVersion = intval(key($migrationFiles));
        }

        if ($desiredVersion !== $databaseVersion)
        {
            if ($desiredVersion > $databaseVersion)
            {
                # Performing an upgrade
                foreach ($migrationFiles as $migrationFileVersion => $filepath)
                {
                    if
                    (
                           $migrationFileVersion > $databaseVersion
                        && $migrationFileVersion <= $desiredVersion
                    ) {
                        $className = self::includeFileAndGetClassName($filepath);

                        /* @var $migrationObject MigrationInterface */
                        $migrationObject = new $className();
                        $migrationObject->up($this->m_mysqliConn);

                        # Update the version after every successful migration in case a later one
                        # fails
                        $this->insertDbVersion($migrationFileVersion);
                    }
                }
            }
            else
            {
                # performing a downgrade
                krsort($migrationFiles);

                foreach ($migrationFiles as $migrationFileVersion => $filepath)
                {
                    if
                    (
                           $migrationFileVersion <= $databaseVersion
                        && $migrationFileVersion > $desiredVersion
                    )
                    {
                        $className = self::includeFileAndGetClassName($filepath);

                        /* @var $migrationObject MigrationInterface */
                        $migrationObject = new $className();
                        $migrationObject->down($this->m_mysqliConn);

                        # Update the version after every successful migration in case a later one
                        # fails
                        $this->insertDbVersion(($migrationFileVersion - 1));
                    }
                }
            }
        }
    }


    /**
     * Fetches the migration files from the migrations folder.
     * @return Array<int,string> $keyedFiles - map of version/filepath to migration script
     * @throws Exception if two files have same version or there is a gap in versions.
     */
    private function getMigrationFiles(): array
    {
        // Find all the migration files in the directory and return the sorted.
        $files = scandir($this->m_migrationsFolder);

        $keyedFiles = array();

        foreach ($files as $filename)
        {
            if (!is_dir($this->m_migrationsFolder . '/' . $filename))
            {
                $fileVersion = self::getFileVersion($filename);

                if (isset($keyedFiles[$fileVersion]))
                {
                    throw new Exception('Migration error: two files have the same version!');
                }

                $keyedFiles[$fileVersion] = $this->m_migrationsFolder . '/' . $filename;
            }
        }

        ksort($keyedFiles);

        # Check that the migration files don't have gaps which could be the result of human error.
        $cachedVersion = null;

        $versions = array_keys($keyedFiles);

        foreach ($versions as $version)
        {
            if ($cachedVersion !== null)
            {
                if ($version != ($cachedVersion + 1))
                {
                    throw new Exception('There is a gap in your migration file versions!');
                }

                $cachedVersion = $version;
            }
        }

        return $keyedFiles;
    }


    /**
     * Given a file that has NOT already been included, this function will return the name
     * of the class within that file AFTER having included it.
     * Warning: This function works on the assumption that only one class is defined in the
     * migration script!
     * @param string $filepath
     * @return string
     * @throws Exception
     */
    private function includeFileAndGetClassName(string $filepath): string
    {
        $existingClasses = get_declared_classes();
        require_once($filepath);
        $afterClasses = get_declared_classes();
        $new_classes = array_diff($afterClasses, $existingClasses);

        if (count($new_classes) == 0)
        {
            $errMsg = 'Migration error: Could not find new class from including migration script' .
                '. This could be caused by having duplicate class names, or having already ' .
                'included the migration script.';

            throw new Exception($errMsg);
        }
        elseif (count($new_classes) > 1)
        {
            $errMsg = 'Migration error: Found more than 1 class defined in the migration script ' .
                '[' . $filepath . ']';

            throw new Exception($errMsg);
        }

        # newClasses array keeps its keys, so the first element is not at 0 at this point
        $new_classes = array_values($new_classes);
        return $new_classes[0];
    }


    /**
     * Function responsible for deciphering the 'version' from a filename. This is a function
     * because we may wish to change it easily.
     * @param string $filename - the name of the file (not full path) that is a migration class.
     * @return int $version - the version the file represents.
     */
    private static function getFileVersion(string $filename): int
    {
        return intval($filename);
    }


    /**
     * Inserts the specified version number into the database.
     * @param int $version - the new version of the database.
     * @return void.
     * @throws Exception
     */
    private function insertDbVersion(int $version): void
    {
        $query =
            "REPLACE INTO `$this->m_escapedTableName` " .
            "SET `id`='1', `version`='" . $version . "'";

        $result = $this->m_mysqliConn->query($query);

        if ($result === false)
        {
            throw new Exception("Migrations: error inserting version into the database");
        }
    }


    /**
     * Fetches the version of the database from the database.
     * @return int $version - the version in the database if it exists, -1 if it doesn't.
     * @throws Exception if migration table exists but failed to fetch version.
     */
    private function getDbVersion(): int
    {
        $result = $this->m_mysqliConn->query("SHOW TABLES LIKE '{$this->m_escapedTableName}'");

        if ($result->num_rows > 0)
        {
            $query = "SELECT * FROM `{$this->m_escapedTableName}`";
            $result = $this->m_mysqliConn->query($query);

            if ($result === FALSE || $result->num_rows == 0)
            {
                # Appears that we have the migrations table but no version row, which may be the 
                # result of a previously erroneous upgrade attempt, so return that no version is set.
                $version = -1;
            }
            else
            {
                $row = $result->fetch_assoc();

                if ($row == null || !isset($row['version']))
                {
                    throw new Exception('Migrations: error reading database version from database');
                }

                $version = $row['version'];
            }
        }
        else
        {
            $this->createMigrationTable();
            $version = -1; # just in case the users migration files start at 0 and not 1
        }

        return $version;
    }


    /**
     * Creates the migration table for if it doesn't exist yet to store the version within.
     */
    private function createMigrationTable(): void
    {
        $query =
            "CREATE TABLE `{$this->m_escapedTableName}` (
                id int(1) auto_increment primary key,
                version int(4) not null
            )";

        $this->m_mysqliConn->query($query);
    }
}

