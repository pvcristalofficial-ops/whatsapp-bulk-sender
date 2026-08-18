<?php
// config/database.php

define('DB_HOST', 'localhost');
define('DB_NAME', 'whatsapp_bulk_sender');
define('DB_USER', 'root');
define('DB_PASS', '');

class Database {
    private static ?PDO $connection = null;

    public static function getConnection(): PDO {
        if (self::$connection === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ];
                self::$connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // Log and display detailed error
                error_log("Database connection error: " . $e->getMessage());
                die("❌ Database connection failed!\n\n" . 
                    "Error: " . $e->getMessage() . "\n\n" .
                    "Steps to fix:\n" .
                    "1. Ensure MySQL is running in XAMPP\n" .
                    "2. Import database.sql file to create tables\n" .
                    "3. Verify credentials in config/database.php\n" .
                    "4. Enable pdo_mysql extension in php.ini\n");
            }
        }
        return self::$connection;
    }
}
