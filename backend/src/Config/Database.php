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