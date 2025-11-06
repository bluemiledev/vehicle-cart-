<?php
/**
 * Redis Connection Test Script
 * 
 * Access this file via browser to test Redis connection:
 * https://yourdomain.com/backend/test_redis.php
 */

header('Content-Type: application/json');

$result = [
    'timestamp' => date('Y-m-d H:i:s'),
    'php_redis_extension' => class_exists('Redis'),
    'redis_connection' => false,
    'redis_host' => 'localhost',
    'redis_port' => 6379,
    'cache_test' => false,
    'error' => null
];

if (!$result['php_redis_extension']) {
    $result['error'] = 'PHP Redis extension is not installed';
    $result['status'] = '⚠️ PHP Redis extension missing';
    $result['message'] = 'System will use file/database fallback';
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

try {
    $redis = new Redis();
    $connected = $redis->connect('localhost', 6379, 2); // 2 second timeout
    
    if ($connected) {
        $result['redis_connection'] = true;
        
        // Test ping
        $ping_result = $redis->ping();
        
        // Test read/write
        $test_key = 'test_redis_' . time();
        $test_value = 'test_' . uniqid();
        $redis->setex($test_key, 10, $test_value);
        $retrieved = $redis->get($test_key);
        $redis->del($test_key);
        
        $result['cache_test'] = ($retrieved === $test_value);
        
        // Check for existing cache keys
        $dates_keys = $redis->keys('dates:*');
        $processed_keys = $redis->keys('processed_data:*');
        $vehicles_keys = $redis->keys('vehicles:*');
        
        $result['cache_keys'] = [
            'dates' => count($dates_keys),
            'processed_data' => count($processed_keys),
            'vehicles' => count($vehicles_keys)
        ];
        
        if ($result['cache_test']) {
            $result['status'] = '✅ Redis is working perfectly!';
            $result['message'] = 'Cache is operational. API will use Redis for fast responses.';
        } else {
            $result['status'] = '⚠️ Redis connected but cache test failed';
            $result['message'] = 'Check Redis permissions';
        }
    } else {
        $result['error'] = 'Could not connect to Redis server';
        $result['status'] = '❌ Redis connection failed';
        $result['message'] = 'System will use file/database fallback';
    }
    
    if ($redis) {
        $redis->close();
    }
    
} catch (Exception $e) {
    $result['error'] = $e->getMessage();
    $result['status'] = '❌ Redis error';
    $result['message'] = 'System will use file/database fallback';
}

echo json_encode($result, JSON_PRETTY_PRINT);
?>


