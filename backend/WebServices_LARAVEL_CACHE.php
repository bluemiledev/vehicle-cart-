<?php
/**
 * Alternative Implementation Using Laravel Cache
 * 
 * This version uses Laravel's Cache facade instead of direct Redis.
 * Benefits:
 * - Works with any cache driver (Redis, Memcached, File, Database)
 * - More Laravel-standard approach
 * - Easier configuration
 * - Can fallback to file cache if Redis unavailable
 */

namespace App\Http\Controllers;

use Session;
use Illuminate\Http\Request;
use DB; 
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Part\TextPart;
use Illuminate\Support\Facades\Validator;
use App\Traits\ProjectHelpersTrait;
use Illuminate\Support\Facades\Cache; // Laravel Cache facade

class WebServices extends Controller{
    
    private $cache_expiry = 60; // 60 second cache expiry
    private $cache_available = false;
    
    function __construct() {
        header('Content-Type: application/json; charset=utf-8');
        // CORS Headers
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
        error_reporting(0);
        
        // ========================================================================
        // TEST CACHE AVAILABILITY: Use Laravel Cache (works with Redis, File, etc.)
        // ========================================================================
        try {
            // Test if cache is working
            $test_key = 'cache_test_' . time();
            Cache::put($test_key, 'test', 1);
            $value = Cache::get($test_key);
            Cache::forget($test_key);
            
            if ($value === 'test') {
                $this->cache_available = true;
                error_log("✅ Cache system is available (driver: " . config('cache.default') . ")");
            }
        } catch (\Exception $e) {
            $this->cache_available = false;
            error_log("Cache system not available: " . $e->getMessage() . ". Will use database fallback.");
        }
    }
    
    /**
     * Get data from cache (works with any cache driver)
     */
    private function getFromCache($cache_key) {
        if (!$this->cache_available) {
            return null;
        }
        
        try {
            $cached_data = Cache::get($cache_key);
            
            if ($cached_data !== null) {
                error_log("✅ Cache HIT (key: {$cache_key})");
                return $cached_data;
            }
            
            error_log("⚠️  Cache MISS (key: {$cache_key})");
            return null;
        } catch (\Exception $e) {
            error_log("Cache read error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Cache data (works with any cache driver)
     */
    private function setToCache($cache_key, $data, $expiry = null) {
        if (!$this->cache_available) {
            return false;
        }
        
        try {
            Cache::put($cache_key, $data, $expiry ?? $this->cache_expiry);
            error_log("✅ Data cached (key: {$cache_key}, expiry: " . ($expiry ?? $this->cache_expiry) . "s)");
            return true;
        } catch (\Exception $e) {
            error_log("Cache write error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get data from Redis cache
     */
    private function getFromRedis($device_id, $date) {
        $cache_key = "processed_data:{$device_id}:{$date}";
        return $this->getFromCache($cache_key);
    }
    
    /**
     * Cache data to Redis
     */
    private function setToRedis($device_id, $date, $data) {
        $cache_key = "processed_data:{$device_id}:{$date}";
        return $this->setToCache($cache_key, $data);
    }
    
    /**
     * Get dates from cache
     */
    private function getDatesFromRedis($vehicles_id) {
        $cache_key = "dates:{$vehicles_id}";
        $data = $this->getFromCache($cache_key);
        
        if ($data !== null && is_array($data)) {
            error_log("✅ Dates Cache HIT (key: {$cache_key}, count: " . count($data) . ")");
        }
        
        return $data;
    }
    
    /**
     * Cache dates to Redis
     */
    private function setDatesToRedis($vehicles_id, $dates) {
        $cache_key = "dates:{$vehicles_id}";
        return $this->setToCache($cache_key, $dates);
    }
    
    /**
     * Get data from JSON file (fallback)
     */
    private function getFromFile($device_id, $date) {
        // For Laravel: Get project root path
        if (function_exists('base_path')) {
            $json_file_path = base_path('data.json');
        } else {
            $json_file_path = dirname(dirname(dirname(__DIR__))) . '/data.json';
        }
        
        if (!file_exists($json_file_path)) {
            error_log("⚠️  JSON file not found: {$json_file_path}");
            return null;
        }
        
        try {
            $file_content = file_get_contents($json_file_path);
            $json_data = json_decode($file_content, true);
            
            if ($json_data === null) {
                error_log("⚠️  Invalid JSON in file");
                return null;
            }
            
            if (isset($json_data['device_id']) && isset($json_data['date'])) {
                if ($json_data['device_id'] == $device_id && $json_data['date'] == $date) {
                    error_log("✅ Data loaded from JSON file");
                    return $json_data['data'];
                }
            }
            
            return $json_data;
        } catch (Exception $e) {
            error_log("File read error: " . $e->getMessage());
            return null;
        }
    }
    
    function show_error($message) {
        echo json_encode(array("status" => "0","message"=>$message,"data" => array()));
        die();
    }
    
    function show_data($message,$data){
        echo json_encode(array("status" => "1","message"=>$message,"data" => $data));
        die();
    }
    
    // ... rest of your methods (get_vehicles, get_dates_by_vehicles_id, get_charts_data_1, etc.)
    // Use the same logic but with getFromCache/setToCache methods above
    
    function get_vehicles(Request $request){
        $start_time = microtime(true);
        
        $cache_key_params = [
            'search' => $request->search ?? '',
            'status' => $request->status ?? ''
        ];
        $cache_key_suffix = md5(json_encode($cache_key_params));
        $cache_key = "vehicles:{$cache_key_suffix}";
        
        // Check cache
        $cached_data = $this->getFromCache($cache_key);
        if ($cached_data !== null) {
            $response_time = (microtime(true) - $start_time) * 1000;
            error_log("⚡ Vehicles Cache HIT - Response time: " . round($response_time, 2) . "ms");
            $this->show_data("Vehicles (from cache)", $cached_data);
            return;
        }
        
        // Query database
        error_log("⚠️  Vehicles Cache MISS - Querying database");
        
        $query = DB::table("vehicles");
        if($request->search){
            $query->whereRaw("rego like '%" . trim($request->search) . "%' ");
        }
        if($request->status){
            $query->whereRaw("status like '" . trim($request->status) . "' ");
        }
        $vehicles = $query->where("status","Active")->orderby("id","desc")->get();
        
        $data = [];
        foreach($vehicles as $vehicle) { 
            $data[] = array(
                "id" => $vehicle->id,
                "rego" => $vehicle->rego ."(" . $vehicle->fleet_no . ")",
            );
        }
        
        // Cache result
        $this->setToCache($cache_key, $data);
        
        $response_time = (microtime(true) - $start_time) * 1000;
        error_log("🗄️  Vehicles Database Query - Response time: " . round($response_time, 2) . "ms");
        
        $this->show_data("Vehicles", $data);
    }
    
    function get_dates_by_vehicles_id(Request $request){
        header('Content-Type: application/json; charset=utf-8');
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
        error_reporting(0);
        
        if(!$request->vehicles_id){
            $this->show_error("Vehicle id compulsory");
            return;
        }
        
        $vehicles_id = $request->vehicles_id;
        $start_time = microtime(true);
        
        // Check cache
        $cached_dates = $this->getDatesFromRedis($vehicles_id);
        
        if ($cached_dates !== null) {
            $response_time = (microtime(true) - $start_time) * 1000;
            error_log("⚡ Dates Cache HIT - Response time: " . round($response_time, 2) . "ms");
            $this->show_data("Dates (from cache)", $cached_dates);
            return;
        }
        
        // Query database
        error_log("⚠️  Dates Cache MISS - Querying database for vehicles_id: {$vehicles_id}");
        
        $device_details = DB::table("devices")->where("vehicles_id", $vehicles_id)->first();
        
        if($device_details && isset($device_details->serial_no)) { 
            $device_id = $device_details->serial_no;
            
            $query_start = microtime(true);
            
            $results = DB::select("
                SELECT DISTINCT date as d
                FROM csv_data1
                WHERE device_id = ?
                AND (
                    (actual_min > 0 AND actual_max > 0 AND actual_avg > 0)
                    OR actual_value = 1
                )
                ORDER BY date DESC
            ", [$device_id]);
            
            $query_time = (microtime(true) - $query_start) * 1000;
            error_log("📊 Database query executed in: " . round($query_time, 2) . "ms");
            
            $data = array_map(function($row) {
                return $row->d;
            }, $results);
            
            // Cache result
            $this->setDatesToRedis($vehicles_id, $data);
            
            $response_time = (microtime(true) - $start_time) * 1000;
            error_log("🗄️  Dates Database Query - Total response time: " . round($response_time, 2) . "ms");
            
            $this->show_data("Dates", $data);
        }
        else {
            $this->setDatesToRedis($vehicles_id, []);
            $this->show_error("Vehicle or device id not matched");        
        }
    }
    
    // ... include your other methods (get_charts_data_1, get_readings, manual_readings, etc.)
    // Update them to use getFromCache/setToCache instead of direct Redis calls
}


