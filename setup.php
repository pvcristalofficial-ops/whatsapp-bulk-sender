<?php
/**
 * Database Setup Script
 * Run this once to create all database tables
 * Visit: http://localhost/whatsapp-bulk-sender/setup.php
 */

try {
    // Step 1: Connect to MySQL server (without database)
    $pdo = new PDO(
        "mysql:host=localhost;charset=utf8mb4",
        "root",
        "",
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]
    );

    // Step 2: Create database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `whatsapp_bulk_sender` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // Step 3: Select database
    $pdo->exec("USE `whatsapp_bulk_sender`");

    // Step 4: Read the SQL file
    $sqlFile = __DIR__ . '/database.sql';
    if (!file_exists($sqlFile)) {
        die("❌ Error: database.sql file not found!");
    }

    $sql = file_get_contents($sqlFile);
    
    // Remove comments
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    
    // Remove the CREATE DATABASE and USE statements (already executed above)
    $sql = preg_replace('/CREATE DATABASE.*?;/is', '', $sql);
    $sql = preg_replace('/USE\s+`?whatsapp_bulk_sender`?;/i', '', $sql);
    
    // Split and execute
    $statements = array_filter(array_map('trim', explode(';', $sql)), 'strlen');
    
    $count = 0;
    $errors = [];
    
    foreach ($statements as $statement) {
        if (!empty(trim($statement))) {
            try {
                $pdo->exec($statement);
                $count++;
            } catch (PDOException $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    echo "<html><head><style>body { font-family: Arial, sans-serif; margin: 20px; }</style></head><body>";
    echo "<h2 style='color: green;'>✓ Database setup completed successfully!</h2>";
    echo "<p><strong>✓ Database created:</strong> whatsapp_bulk_sender</p>";
    echo "<p><strong>✓ Tables created:</strong> " . $count . " statements executed</p>";
    
    if (!empty($errors)) {
        echo "<p style='color: orange;'><strong>Note:</strong> Some statements had warnings (this is usually normal for duplicate key errors):</p>";
        echo "<ul>";
        foreach (array_unique($errors) as $error) {
            echo "<li style='color: #666;'>" . htmlspecialchars($error) . "</li>";
        }
        echo "</ul>";
    }
    
    echo "<p style='margin-top: 30px;'><a href='index.php?page=login' style='padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>✓ Go to Login Page →</a></p>";
    echo "</body></html>";

} catch (PDOException $e) {
    echo "<html><head><style>body { font-family: Arial, sans-serif; margin: 20px; }</style></head><body>";
    echo "<h2 style='color: red;'>❌ Database setup failed!</h2>";
    echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Troubleshooting steps:</strong></p>";
    echo "<ol>";
    echo "<li><strong>Check MySQL is running:</strong> Open XAMPP Control Panel and click 'Start' next to MySQL</li>";
    echo "<li><strong>Verify MySQL credentials:</strong> Open config/database.php and check DB_HOST, DB_USER, DB_PASS</li>";
    echo "<li><strong>Try manual import:</strong> Visit http://localhost/phpmyadmin → Import → Select database.sql</li>";
    echo "</ol>";
    echo "<p style='color: #666;'><strong>Technical details:</strong><br>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</body></html>";
}
?>
