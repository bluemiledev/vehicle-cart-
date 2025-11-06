<?php
/**
 * Deployment Verification Script
 * 
 * Access this file via browser to verify all backend updates are deployed:
 * https://yourdomain.com/backend/verify_deployment.php
 */

header('Content-Type: application/json');

$checks = [
    'php_redis_extension' => false,
    'redis_connection' => false,
    'cache_read_write' => false,
    'files_exist' => [],
    'api_code_updated' => false,
    'errors' => []
];

$base_dir = __DIR__;

// Check PHP Redis Extension
$checks['php_redis_extension'] = class_exists('Redis');

// Check Redis Connection and Cache
if ($checks['php_redis_extension']) {
    try {
        $redis = new Redis();
        $connected = $redis->connect('localhost', 6379, 2);
        
        if ($connected) {
            $checks['redis_connection'] = true;
            $redis->ping();
            
            // Test read/write
            $test_key = 'verify_deployment_' . time();
            $test_value = 'test_value_' . uniqid();
            $redis->setex($test_key, 10, $test_value);
            $retrieved = $redis->get($test_key);
            $checks['cache_read_write'] = ($retrieved === $test_value);
            $redis->del($test_key);
            
            // Check existing cache
            $checks['cache_keys'] = [
                'dates' => count($redis->keys('dates:*')),
                'processed_data' => count($redis->keys('processed_data:*')),
                'vehicles' => count($redis->keys('vehicles:*'))
            ];
        }
    } catch (Exception $e) {
        $checks['errors'][] = 'Redis error: ' . $e->getMessage();
    }
} else {
    $checks['errors'][] = 'PHP Redis extension not installed';
}

// Check Required Files
// Laravel structure: app/Http/Controllers/WebServices.php
$required_files = [
    'generate_json_cache.py' => $base_dir . '/python_main/generate_json_cache.py',
    'load_files.py' => $base_dir . '/python_main/load_files.py',
    '.env' => $base_dir . '/.env',
    'WebServices.php' => $base_dir . '/app/Http/Controllers/WebServices.php',
    'WebServices_redis.php' => $base_dir . '/WebServices_redis.php', // Check if optimized version exists
];

foreach ($required_files as $name => $path) {
    $checks['files_exist'][$name] = file_exists($path);
}

// Check if API code has Redis methods
// Check Laravel controller location
$webservices_file = $base_dir . '/app/Http/Controllers/WebServices.php';
if (file_exists($webservices_file)) {
    $content = file_get_contents($webservices_file);
    $checks['api_code_updated'] = (
        strpos($content, 'getDatesFromRedis') !== false ||
        strpos($content, 'connectRedis') !== false ||
        strpos($content, 'Redis') !== false
    );
    $checks['webservices_file_path'] = 'app/Http/Controllers/WebServices.php';
} else {
    // Fallback check if file exists in root
    $webservices_file_root = $base_dir . '/WebServices.php';
    if (file_exists($webservices_file_root)) {
        $content = file_get_contents($webservices_file_root);
        $checks['api_code_updated'] = (
            strpos($content, 'getDatesFromRedis') !== false ||
            strpos($content, 'connectRedis') !== false ||
            strpos($content, 'Redis') !== false
        );
        $checks['webservices_file_path'] = 'WebServices.php (root)';
    }
}

// Calculate overall status
$critical_checks = [
    $checks['php_redis_extension'],
    $checks['api_code_updated'],
    $checks['files_exist']['WebServices.php']
];

$all_critical_passed = count(array_filter($critical_checks)) === count($critical_checks);

$result = [
    'deployment_status' => $all_critical_passed ? '✅ DEPLOYED' : '❌ NOT FULLY DEPLOYED',
    'timestamp' => date('Y-m-d H:i:s'),
    'checks' => $checks,
    'recommendations' => []
];

// Add recommendations
if (!$checks['php_redis_extension']) {
    $result['recommendations'][] = 'Install PHP Redis extension (system will use fallback)';
}

if (!$checks['redis_connection']) {
    $result['recommendations'][] = 'Check Redis server is running on localhost:6379';
}

if (!$checks['cache_read_write']) {
    $result['recommendations'][] = 'Verify Redis read/write permissions';
}

if (!$checks['api_code_updated']) {
    $result['recommendations'][] = 'Update WebServices.php with Redis caching code';
}

if (!$checks['files_exist']['generate_json_cache.py']) {
    $result['recommendations'][] = 'Upload generate_json_cache.py file';
}

if (!$checks['files_exist']['.env']) {
    $result['recommendations'][] = 'Create .env file with Redis configuration';
}

// Summary
$result['summary'] = [
    'redis_working' => $checks['redis_connection'] && $checks['cache_read_write'],
    'files_uploaded' => count(array_filter($checks['files_exist'])) === count($checks['files_exist']),
    'code_updated' => $checks['api_code_updated'],
    'ready_for_production' => $all_critical_passed && $checks['redis_connection']
];

echo json_encode($result, JSON_PRETTY_PRINT);
?>

