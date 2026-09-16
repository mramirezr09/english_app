<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Base class for database-backed models. Shares a single PDO connection.
 */
abstract class Model
{
    protected PDO $db;

    private static ?PDO $connection = null;

    public function __construct()
    {
        $this->db = self::connection();
    }

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            Config::ensureDirectory(Config::dataDir());
            $pdo = new PDO('sqlite:' . Config::dbPath());
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$connection = $pdo;
        }
        return self::$connection;
    }
}
