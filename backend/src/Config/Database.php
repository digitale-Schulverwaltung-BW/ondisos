<?php
// src/Config/Database.php
// singleton for database connection

declare(strict_types=1);

namespace App\Config;

use mysqli;
use RuntimeException;

class Database
{
    private static ?mysqli $connection = null;

    private function __construct() {}

    /**
     * Explain the most common reason for a login/schema failure on the CLI (migrate.php, seed-forms.php).
     *
     * With Docker, MYSQL_USER / MYSQL_PASSWORD / MYSQL_ROOT_PASSWORD / MYSQL_DATABASE are applied ONLY when the MySQL
     * data volume is first created. Changing DB_USER, DB_PASS or DB_NAME in the root .env afterwards leaves the
     * volume with the old credentials, so the backend is rejected ("Access denied" / "Unknown database").
     *
     * @return string|null Hint text, or null when the message is not recognised
     */
    public static function connectionHint(string $message): ?string
    {
        if (stripos($message, 'Access denied') === false && stripos($message, 'Unknown database') === false) {
            return null;
        }

        return <<<TXT
Hint: the MySQL data volume keeps the credentials it was CREATED with. MYSQL_USER, MYSQL_PASSWORD, MYSQL_ROOT_PASSWORD and
MYSQL_DATABASE (from DB_USER, DB_PASS, MYSQL_ROOT_PASSWORD, DB_NAME in the root .env) are only applied on the first start.
If you changed them afterwards, either
  - restore the original values in the root .env, or
  - set the new password inside MySQL (needs the OLD root password):
      docker compose exec mysql mysql -uroot -p -e "ALTER USER 'anmeldung'@'%' IDENTIFIED BY '<new>'"
  - or start from scratch (DELETES ALL DATA of that database): stop backend and mysql, remove ONLY the MySQL volume
    (find it with: docker volume ls | grep mysql-data), then start again - never use "docker compose down -v" here, it
    also deletes uploads and other volumes.
Details: DEPLOYMENT.md, section "Datenbank-Zugriff verweigert".
TXT;
    }

    public static function getConnection(): mysqli
    {
        if (self::$connection === null) {
            self::$connection = self::createConnection();
        }

        return self::$connection;
    }

    private static function createConnection(): mysqli
    {
        $config = Config::getInstance();
        
        $mysqli = new mysqli(
            $config->dbHost,
            $config->dbUser,
            $config->dbPass,
            $config->dbName,
            $config->dbPort
        );

        if ($mysqli->connect_errno) {
            error_log('DB Connection failed: ' . $mysqli->connect_error);
            throw new RuntimeException('Database not reachable', 500);
        }

        $mysqli->set_charset('utf8mb4');

        return $mysqli;
    }

    public static function closeConnection(): void
    {
        if (self::$connection !== null) {
            self::$connection->close();
            self::$connection = null;
        }
    }
}