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

namespace iRAP\Migrations;

use Exception;
use iRAP\TableCreator\DatabaseField;
use iRAP\TableCreator\TableCreator;
use mysqli;

class MigrationManager
{
    private readonly string $m_table_name;
    private readonly string $m_escaped_table_name;
    private mysqli $m_mysqli_conn; #  A mysqli connection object that will be used to manipulate the db.
    private readonly string $m_schemas_folder; # The folder in which migration scripts are located.

    /**
     * Creates the Migration object in preparation for migration.
     * @param string $migration_folder the path to the folder containing all the migration scripts
     * this may be absolute or relative.
     * @param mysqli $connection a Mysqli object connecting us to the database.
     * @param string $table table name for version info
     */
    public function __construct(string $migration_folder, mysqli $connection, string $table = 'migrations')
    {
        $this->m_schemas_folder = $migration_folder;
        $this->m_mysqli_conn = $connection;
        $this->m_table_name = $table;
        $this->m_escaped_table_name = $connection->escape_string($table);
    }

    /**
     * Migrates the database to the specified version. If the version is not specified (null) then
     * this will automatically migrate the database to the furthest point which is determined by
     * looking at the schemas.
     * @param ?int $desired_version optional parameter to specify the version we wish to migrate to.
     *                   if not set, then this will automatically migrate to the latest version
     *                   which is discovered by looking at the files.
     * @return void updates database.
     * @throws Exception
     */
    public function migrate(int $desired_version = null): void
    {
        $databaseVersion = $this->get_db_version();
        $migrationFiles = $this->get_migration_files();

        if ($desired_version === null) {
            end($migrationFiles); # move the internal pointer to the end of the array
            $desired_version = intval(key($migrationFiles));
        }

        if ($desired_version !== $databaseVersion) {
            if ($desired_version > $databaseVersion) {
                # Performing an upgrade
                foreach ($migrationFiles as $migrationFileVersion => $filepath) {
                    if
                    (
                        $migrationFileVersion > $databaseVersion &&
                        $migrationFileVersion <= $desired_version
                    ) {
                        $className = self::include_file_and_get_class_name($filepath);

                        /* @var $migrationObject MigrationInterface */
                        $migrationObject = new $className();
                        $migrationObject->up($this->m_mysqli_conn);

                        # Update the version after every successful migration in case a later one
                        # fails
                        $this->insert_db_version($migrationFileVersion);
                    }
                }
            } else {
                # performing a downgrade
                krsort($migrationFiles);

                foreach ($migrationFiles as $migrationFileVersion => $filepath) {
                    if
                    (
                        $migrationFileVersion <= $databaseVersion &&
                        $migrationFileVersion > $desired_version
                    ) {
                        $className = self::include_file_and_get_class_name($filepath);

                        /* @var $migrationObject MigrationInterface */
                        $migrationObject = new $className();
                        $migrationObject->down($this->m_mysqli_conn);

                        # Update the version after every successful migration in case a later one
                        # fails
                        $this->insert_db_version(($migrationFileVersion - 1));
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
    private function get_migration_files(): array
    {
        // Find all the migration files in the directory and return the sorted.
        $files = scandir($this->m_schemas_folder);

        $keyedFiles = array();

        foreach ($files as $filename) {
            if (!is_dir($this->m_schemas_folder . '/' . $filename)) {
                $fileVersion = self::get_file_version($filename);

                if (isset($keyedFiles[$fileVersion])) {
                    throw new Exception('Migration error: two files have the same version!');
                }

                $keyedFiles[$fileVersion] = $this->m_schemas_folder . '/' . $filename;
            }
        }

        ksort($keyedFiles);

        # Check that the migration files don't have gaps which could be the result of human error.
        $cachedVersion = null;

        $versions = array_keys($keyedFiles);

        foreach ($versions as $version) {
            if ($cachedVersion !== null) {
                if ($version != ($cachedVersion + 1)) {
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
    private function include_file_and_get_class_name(string $filepath): string
    {
        $existingClasses = get_declared_classes();
        require_once($filepath);
        $afterClasses = get_declared_classes();
        $new_classes = array_diff($afterClasses, $existingClasses);

        if (count($new_classes) == 0) {
            $errMsg = 'Migration error: Could not find new class from including migration script' .
                '. This could be caused by having duplicate class names, or having already ' .
                'included the migration script.';

            throw new Exception($errMsg);
        } elseif (count($new_classes) > 1) {
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
    private static function get_file_version(string $filename): int
    {
        return intval($filename);
    }

    /**
     * Inserts the specified version number into the database.
     * @param int $version - the new version of the database.
     * @return void.
     * @throws Exception
     */
    private function insert_db_version(int $version): void
    {
        $query =
            "REPLACE INTO `$this->m_escaped_table_name` " .
            "SET `id`='1', `version`='" . $version . "'";

        $result = $this->m_mysqli_conn->query($query);

        if ($result === false) {
            throw new Exception("Migrations: error inserting version into the database");
        }
    }

    /**
     * Fetches the version of the database from the database.
     * @return int $version - the version in the database if it exists, -1 if it doesn't.
     * @throws Exception if migration table exists but failed to fetch version.
     */
    private function get_db_version(): int
    {
        $result = $this->m_mysqli_conn->query("SHOW TABLES LIKE '$this->m_escaped_table_name'");

        if ($result->num_rows > 0) {
            $query = "SELECT * FROM `$this->m_escaped_table_name`";
            $result = $this->m_mysqli_conn->query($query);

            if ($result === FALSE || $result->num_rows == 0) {
                # Appears that we have the migrations table but no version row, which may be the 
                # result of a previously erroneous upgrade attempt, so return that no version is set.
                $version = -1;
            } else {
                $row = $result->fetch_assoc();

                if ($row == null || !isset($row['version'])) {
                    throw new Exception('Migrations: error reading database version from database');
                }

                $version = $row['version'];
            }

        } else {
            $this->create_migration_table();
            $version = -1; # just in case the users migration files start at 0 and not 1
        }

        return $version;
    }

    /**
     * Creates the migration table for if it doesn't exist yet to store the version within.
     * @return void
     * @throws Exception
     */
    private function create_migration_table(): void
    {
        $tableCreator = new TableCreator($this->m_mysqli_conn, $this->m_table_name);

        $fields = array(
            DatabaseField::createInt('id', 1, true),
            DatabaseField::createInt('version', 4)
        );

        $tableCreator->addFields($fields);
        $tableCreator->setPrimaryKey('id');
        $tableCreator->run();
    }
}

