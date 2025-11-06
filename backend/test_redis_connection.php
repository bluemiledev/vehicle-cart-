<?php
/**
 * Redis Connection Test Script
 * 
 * Tests Redis connection with current .env settings
 * Access: https://www.no-reply.com.au/smart_data_link/test_redis_connection.php
 */

header('Content-Type: text/plain; charset=utf-8');

echo "========================================\n";
echo "Redis Connection Test\n";
echo "========================================\n\n";

// Load environment variables
$host = getenv('REDIS_HOST') ?: 'localhost';
$port = intval(getenv('REDIS_PORT') ?: 6379);
$password = getenv('REDIS_PASSWORD') ?: null;
$db = intval(getenv('REDIS_DB') ?: 0);

echo "Configuration from .env:\n";
echo "  Host: {$host}\n";
echo "  Port: {$port}\n";
echo "  Password: " . ($password ? '***' : '(none)') . "\n";
echo "  DB: {$db}\n\n";

echo "Testing connection...\n";

if (!class_exists('Redis')) {
    echo "❌ PHP Redis extension not installed\n";
    echo "   Contact hosting provider to install PHP Redis extension\n";
    exit;
}

echo "✅ PHP Redis extension is installed\n\n";

try {
    $redis = new Redis();
    echo "Attempting to connect to {$host}:{$port}...\n";
    
    $connected = $redis->connect($host, $port, 2); // 2 second timeout
    
    if (!$connected) {
        echo "❌ Connection failed\n";
        echo "\nPossible causes:\n";
        echo "  1. Redis server not running\n";
        echo "  2. Wrong host/port in .env\n";
        echo "  3. Firewall blocking connection\n";
        echo "  4. Redis not installed on server\n";
        echo "\nSolution: Contact hosting provider or check Redis server status\n";
        exit;
    }
    
    echo "✅ Connected to Redis server\n";
    
    // Authenticate if password set
    if ($password !== null) {
        try {
            $redis->auth($password);
            echo "✅ Authenticated with password\n";
        } catch (Exception $e) {
            echo "❌ Authentication failed: " . $e->getMessage() . "\n";
            exit;
        }
    }
    
    // Select database
    $redis->select($db);
    echo "✅ Selected database {$db}\n";
    
    // Test ping
    $ping_result = $redis->ping();
    echo "✅ Ping successful: {$ping_result}\n";
    
    // Test read/write
    $test_key = 'test_connection_' . time();
    $test_value = 'test_' . uniqid();
    $redis->setex($test_key, 10, $test_value);
    $retrieved = $redis->get($test_key);
    $redis->del($test_key);
    
    if ($retrieved === $test_value) {
        echo "✅ Read/Write test successful\n";
    } else {
        echo "❌ Read/Write test failed\n";
    }
    
    // Check existing keys
    $dates_keys = $redis->keys('dates:*');
    $processed_keys = $redis->keys('processed_data:*');
    $vehicles_keys = $redis->keys('vehicles:*');
    
    echo "\nExisting cache keys:\n";
    echo "  dates: " . count($dates_keys) . " keys\n";
    echo "  processed_data: " . count($processed_keys) . " keys\n";
    echo "  vehicles: " . count($vehicles_keys) . " keys\n";
    
    echo "\n========================================\n";
    echo "✅ Redis is working perfectly!\n";
    echo "Your API will use Redis caching.\n";
    echo "========================================\n";
    
    $redis->close();
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "\nError details:\n";
    echo "  Type: " . get_class($e) . "\n";
    echo "  Message: " . $e->getMessage() . "\n";
    
    echo "\nTroubleshooting:\n";
    echo "  1. Check if Redis server is running\n";
    echo "  2. Verify host/port in .env file\n";
    echo "  3. Check firewall rules\n";
    echo "  4. Contact hosting provider for Redis access\n";
    echo "\nNote: System will work without Redis (uses database fallback)\n";
}
?>


