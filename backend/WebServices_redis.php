<?php

namespace App\Http\Controllers;
use Session;
use Illuminate\Http\Request;
use DB; 
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Part\TextPart;
use Illuminate\Support\Facades\Validator;
use App\Traits\ProjectHelpersTrait;

/**
 * WebServices Controller with Redis Caching
 * 
 * This controller implements a high-performance caching layer using Redis:
 * 1. On API request, first checks Redis cache
 * 2. If cache hit (<200ms response) → returns immediately
 * 3. If cache miss → reads JSON file from disk
 * 4. If file missing → processes database query (legacy fallback)
 * 5. Caches result to Redis for next request (60 second expiry)
 * 
 * Performance: Cache hits return in <200ms, eliminating database queries
 */

class WebServices extends Controller{
    // ============================================================================
    // REDIS CONFIGURATION - Read from environment variables
    // ============================================================================
    private $redis_host;
    private $redis_port;
    private $redis_password;
    private $redis_db;
    private $redis_prefix;
    private $cache_expiry;
    private $redis_client;
    
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
        // REDIS CONNECTION: Initialize Redis connection from environment variables
        // ========================================================================
        $this->redis_host = getenv('REDIS_HOST') ?: 'localhost';
        $this->redis_port = getenv('REDIS_PORT') ?: 6379;
        $this->redis_password = getenv('REDIS_PASSWORD') ?: null;
        $this->redis_db = getenv('REDIS_DB') ?: 0;
        $this->redis_prefix = 'processed_data';  // Key prefix for cached data
        $this->cache_expiry = 60;  // 60 second cache expiry
        
        // Try to connect to Redis (will fallback to file/database if fails)
        $this->redis_client = $this->connectRedis();
    }
    
    /**
     * Connect to Redis server
     * 
     * Returns Redis client or null if connection fails
     * This ensures graceful fallback if Redis is unavailable
     */
    private function connectRedis() {
        if (!class_exists('Redis')) {
            error_log("Redis PHP extension not installed. Falling back to file/database.");
            return null;
        }
        
        try {
            $redis = new \Redis();
            $connected = $redis->connect($this->redis_host, $this->redis_port, 2); // 2 second timeout
            
            if (!$connected) {
                error_log("Redis connection failed. Falling back to file/database.");
                return null;
            }
            
            // Authenticate if password is set
            if ($this->redis_password !== null) {
                $redis->auth($this->redis_password);
            }
            
            // Select database
            $redis->select($this->redis_db);
            
            // Test connection
            $redis->ping();
            
            // Set read timeout to prevent hanging (1 second)
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, 1);
            
            error_log("✅ Redis connected successfully at {$this->redis_host}:{$this->redis_port}");
            return $redis;
        } catch (Exception $e) {
            error_log("Redis connection error: " . $e->getMessage() . ". Falling back to file/database.");
            return null;
        }
    }
    
    /**
     * Get data from Redis cache
     * 
     * @param string $device_id Device ID
     * @param string $date Date in YYYY-MM-DD format
     * @return array|null Cached data or null if not found
     */
    private function getFromRedis($device_id, $date) {
        if ($this->redis_client === null) {
            return null;  // Redis not available, skip cache
        }
        
        try {
            // Create cache key: processed_data:device_id:date
            $cache_key = "{$this->redis_prefix}:{$device_id}:{$date}";
            
            // Try to get from Redis
            $cached_data = $this->redis_client->get($cache_key);
            
            if ($cached_data !== false) {
                // Cache hit! Decode JSON and return
                $data = json_decode($cached_data, true);
                
                if ($data !== null) {
                    error_log("✅ Cache HIT from Redis (key: {$cache_key})");
                    return $data;
                }
            }
            
            // Cache miss
            error_log("⚠️  Cache MISS in Redis (key: {$cache_key})");
            return null;
        } catch (Exception $e) {
            error_log("Redis read error: " . $e->getMessage() . ". Falling back to file/database.");
            return null;
        }
    }
    
    /**
     * Get dates data from Redis cache
     * 
     * @param string $vehicles_id Vehicle ID
     * @return array|null Cached dates array or null if not found
     */
    private function getDatesFromRedis($vehicles_id) {
        if ($this->redis_client === null) {
            return null;  // Redis not available, skip cache
        }
        
        try {
            // Create cache key: dates:vehicles_id
            $cache_key = "dates:{$vehicles_id}";
            
            // Try to get from Redis (with connection timeout already set in connectRedis)
            $cached_data = $this->redis_client->get($cache_key);
            
            if ($cached_data !== false && $cached_data !== null) {
                // Cache hit! Decode JSON and return
                $data = json_decode($cached_data, true);
                
                if ($data !== null && is_array($data)) {
                    error_log("✅ Dates Cache HIT from Redis (key: {$cache_key}, count: " . count($data) . ")");
                    return $data;
                }
            }
            
            // Cache miss
            error_log("⚠️  Dates Cache MISS in Redis (key: {$cache_key})");
            return null;
        } catch (Exception $e) {
            error_log("Redis read error for dates: " . $e->getMessage() . ". Falling back to database.");
            return null;
        }
    }
    
    /**
     * Cache dates data to Redis
     * 
     * @param string $vehicles_id Vehicle ID
     * @param array $dates Dates array to cache
     */
    private function setDatesToRedis($vehicles_id, $dates) {
        if ($this->redis_client === null) {
            return false;  // Redis not available, skip caching
        }
        
        try {
            // Create cache key
            $cache_key = "dates:{$vehicles_id}";
            
            // Serialize data to JSON
            $json_data = json_encode($dates);
            
            // Store in Redis with expiry (60 seconds)
            $this->redis_client->setex($cache_key, $this->cache_expiry, $json_data);
            
            error_log("✅ Dates cached to Redis (key: {$cache_key}, expiry: {$this->cache_expiry}s)");
            return true;
        } catch (Exception $e) {
            error_log("Redis write error for dates: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Cache data to Redis
     * 
     * @param string $device_id Device ID
     * @param string $date Date in YYYY-MM-DD format
     * @param array $data Data to cache
     */
    private function setToRedis($device_id, $date, $data) {
        if ($this->redis_client === null) {
            return false;  // Redis not available, skip caching
        }
        
        try {
            // Create cache key
            $cache_key = "{$this->redis_prefix}:{$device_id}:{$date}";
            
            // Serialize data to JSON
            $json_data = json_encode($data);
            
            // Store in Redis with expiry
            $this->redis_client->setex($cache_key, $this->cache_expiry, $json_data);
            
            error_log("✅ Data cached to Redis (key: {$cache_key}, expiry: {$this->cache_expiry}s)");
            return true;
        } catch (Exception $e) {
            error_log("Redis write error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get data from JSON file (fallback if Redis fails)
     * 
     * @param string $device_id Device ID
     * @param string $date Date in YYYY-MM-DD format
     * @return array|null File data or null if not found
     */
    private function getFromFile($device_id, $date) {
        // ========================================================================
        // FALLBACK TO FILE: If Redis cache miss, try reading JSON file
        // ========================================================================
        // For Laravel: Go up from app/Http/Controllers to project root
        // __DIR__ = app/Http/Controllers, so go up 3 levels to project root
        $json_file_path = base_path('data.json'); // Laravel helper
        // Fallback if base_path not available
        if (!function_exists('base_path')) {
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
                error_log("⚠️  Invalid JSON in file: {$json_file_path}");
                return null;
            }
            
            // Check if file data matches requested device_id and date
            if (isset($json_data['device_id']) && isset($json_data['date'])) {
                if ($json_data['device_id'] == $device_id && $json_data['date'] == $date) {
                    error_log("✅ Data loaded from JSON file (device_id: {$device_id}, date: {$date})");
                    return $json_data['data'];  // Return the actual chart data
                } else {
                    error_log("⚠️  JSON file data doesn't match request (file: {$json_data['device_id']}/{$json_data['date']}, request: {$device_id}/{$date})");
                    return null;
                }
            } else {
                // Legacy format - assume it's the data directly
                error_log("✅ Data loaded from JSON file (legacy format)");
                return $json_data;
            }
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
    
    /**
     * get_vehicles - Optimized with Redis caching
     * 
     * Performance Flow:
     * 1. Check Redis cache first (fastest, <200ms)
     * 2. If cache miss, query database
     * 3. Cache result to Redis for next request
     */
    function get_vehicles(Request $request){
        $start_time = microtime(true);  // Track response time
        
        // Build cache key based on search parameters
        $cache_key_params = [
            'search' => $request->search ?? '',
            'status' => $request->status ?? ''
        ];
        $cache_key_suffix = md5(json_encode($cache_key_params));
        $cache_key = "vehicles:{$cache_key_suffix}";
        
        // ========================================================================
        // STEP 1: CHECK REDIS CACHE FIRST (Fastest path, <200ms response)
        // ========================================================================
        if ($this->redis_client !== null) {
            try {
                $cached_data = $this->redis_client->get($cache_key);
                if ($cached_data !== false) {
                    $data = json_decode($cached_data, true);
                    if ($data !== null && is_array($data)) {
                        $response_time = (microtime(true) - $start_time) * 1000;
                        error_log("⚡ Vehicles Cache HIT - Response time: " . round($response_time, 2) . "ms");
                        $this->show_data("Vehicles (from cache)", $data);
                        return;
                    }
                }
            } catch (Exception $e) {
                error_log("Redis read error for vehicles: " . $e->getMessage());
            }
        }
        
        // ========================================================================
        // STEP 2: FALLBACK TO DATABASE QUERY (If Redis cache miss)
        // ========================================================================
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
        
        // Cache the database result to Redis for next time
        if ($this->redis_client !== null) {
            try {
                $json_data = json_encode($data);
                $this->redis_client->setex($cache_key, $this->cache_expiry, $json_data);
                error_log("✅ Vehicles cached to Redis (key: {$cache_key}, expiry: {$this->cache_expiry}s)");
            } catch (Exception $e) {
                error_log("Redis write error for vehicles: " . $e->getMessage());
            }
        }
        
        $response_time = (microtime(true) - $start_time) * 1000;
        error_log("🗄️  Vehicles Database Query - Response time: " . round($response_time, 2) . "ms");
        
        $this->show_data("Vehicles", $data);
    }
    
    /**
     * get_dates_by_vehicles_id - Optimized with Redis caching
     * 
     * Performance Flow:
     * 1. Check Redis cache first (fastest, <200ms)
     * 2. If cache miss, query database
     * 3. Cache result to Redis for next request
     */
    function get_dates_by_vehicles_id(Request $request){
        header('Content-Type: application/json; charset=utf-8');
        // CORS Headers
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
        $start_time = microtime(true);  // Track response time
        
        // ========================================================================
        // STEP 1: CHECK REDIS CACHE FIRST (Fastest path, <200ms response)
        // ========================================================================
        $cached_dates = $this->getDatesFromRedis($vehicles_id);
        
        if ($cached_dates !== null) {
            // Cache hit! Return immediately
            $response_time = (microtime(true) - $start_time) * 1000;  // Convert to milliseconds
            error_log("⚡ Dates Cache HIT - Response time: " . round($response_time, 2) . "ms");
            
            $this->show_data("Dates (from cache)", $cached_dates);
            return;
        }
        
        // ========================================================================
        // STEP 2: FALLBACK TO DATABASE QUERY (If Redis cache miss)
        // ========================================================================
        error_log("⚠️  Dates Cache MISS - Querying database for vehicles_id: {$vehicles_id}");
        
        // Optimized: Get device details with single query
        $device_details = DB::table("devices")->where("vehicles_id", $vehicles_id)->first();
        
        if($device_details && isset($device_details->serial_no)) { 
            $device_id = $device_details->serial_no;
            
            // OPTIMIZED QUERY: Use raw SQL for better performance with proper indexes
            // This query is optimized to use indexes on (device_id, date) and filter conditions
            $query_start = microtime(true);
            
            // Use raw SQL for maximum performance
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
            
            // Extract dates from results
            $data = array_map(function($row) {
                return $row->d;
            }, $results);
            
            // Cache the database result to Redis for next time (even if empty array)
            $this->setDatesToRedis($vehicles_id, $data);
            
            $response_time = (microtime(true) - $start_time) * 1000;
            error_log("🗄️  Dates Database Query - Total response time: " . round($response_time, 2) . "ms");
            
            $this->show_data("Dates", $data);
        }
        else {
            // Cache empty result to prevent repeated queries for invalid vehicle
            $this->setDatesToRedis($vehicles_id, []);
            
            $this->show_error("Vehicle or device id not matched");        
        }
    }
    
    function get_readings(Request $request){
        if($request->vehicles_id && $request->date ){
            $device_details =   DB::table("devices")->where("vehicles_id",$request->vehicles_id)->first();
            if($device_details->id > 0) { 
                $query          =   DB::table("csv_data1");
                $query->whereRaw("(actual_min is not null and actual_max is not null and actual_avg is not null and actual_value is not null)");
                $query->where("device_id",$device_details->serial_no);
                $query->whereDate('date', date("Y-m-d",strtotime($request->date)));
                $query->whereBetween('timings_id', [1, 1400]);
                
                $response_datas     =   $query->orderBy("id")->get();
                
                $device_locations   =   DB::table("devices_locations")
                    ->where("devices_id", $device->id)
                    ->get()
                    ->keyBy(function($item) {
                    return date("Y-m-d H:i", strtotime($item->date_time));
                });
                
                foreach($response_datas as $response_data) { 
                    $date_time_key  =   date("Y-m-d H:i", strtotime($response_data->date . ' ' . $response_data->time));
                    $location       =   $device_locations[$date_time_key] ?? null;
                    if($response_data->chartType == "Digital"){
                        $value          =   $response_data->inverse == "Yes"?($response_data->actual_value == 1?"0":"1"):$response_data->actual_value; 
                        $tt             =   $response_data->chartName . " (D" . DB::table("manual_readings")->where("id",$response_data->manual_readings_id)->first()->number . ")";
                        if($response_data->units != ""){
                           $tt          .=   " " . $response_data->units;
                        }
                        $data[]         =   array(
                            "chartName"         =>  $tt,
                            "date"              =>  date("Y-m-d", strtotime($response_data->date)) ,
                            "time"              =>  date("H:i:00", strtotime($response_data->time)),
                            "date_time"         =>  date("Y-m-d", strtotime($response_data->date)). " " .date("H:i:00", strtotime($response_data->time)),
                            "value"             =>  (int)$value,   
                            "chartType"         =>  $response_data->chartType,
                            "latitude"          =>  $location->latitude ?? "",
                            "longitude"         =>  $location->longitude ?? "",
                        );  
                    }
                    else {
                        if($response_data->multiplication_factor != "" && $response_data->offset != "" ){ 
                            if($response_data->actual_max > 0) {
                                $max =  ($response_data->actual_max * $response_data->offset) + $response_data->multiplication_factor;
                            }
                            else {
                                $max =   $response_data->actual_max;
                            }
                            if($response_data->actual_min > 0) {
                                $min =  ($response_data->actual_min * $response_data->offset) + $response_data->multiplication_factor;
                            }
                            else {
                                $min =   $response_data->actual_min;
                            }
                            if($response_data->actual_avg > 0) {
                                $avg =  ($response_data->actual_avg * $response_data->offset) + $response_data->multiplication_factor;
                            }
                            else {
                                $avg =   $response_data->actual_avg;
                            }
                        }
                        else {
                            $max =  $response_data->actual_max;
                            $min =  $response_data->actual_min;
                            $avg =   $response_data->actual_avg;   
                        }
                        $tt             =   $response_data->chartName . " (A" . DB::table("manual_readings")->where("id",$response_data->manual_readings_id)->first()->number . ")";
                        if($response_data->units != ""){
                           $tt          .=   " " . $response_data->units;
                        }
                        $data[]           =   array(
                            "chartName"         =>  $tt,
                            "date"              =>  date("Y-m-d", strtotime($response_data->date)) ,
                            "time"              =>  date("H:i:00", strtotime($response_data->time)),
                            "date_time"         =>  date("Y-m-d", strtotime($response_data->date)). " " .date("H:i:00", strtotime($response_data->time)),
                            "max"               =>  (int)$max,
                            "min"               =>  (int)$min,
                            "avg"               =>  (int)$avg,
                            "chartType"         =>  $response_data->chartType,
                            "latitude"          =>  $location->latitude ?? "",
                            "longitude"         =>  $location->longitude ?? "",
                        );
                    }
                }
                
                $this->show_data("Data fetched",$data);
            }
            else {
                $this->show_error("Vehicle or device id not matched");        
            }
        }
        $this->show_error("Vehicle id and date  compulsory");
    }
    
    /**
     * get_charts_data_1 - Optimized with Redis caching
     * 
     * Performance Flow:
     * 1. Check Redis cache first (fastest, <200ms)
     * 2. If cache miss, read JSON file (fallback)
     * 3. If file missing, query database (legacy fallback)
     * 4. Cache result to Redis for next request
     */
    function get_charts_data_1(Request $request){
        header('Content-Type: application/json; charset=utf-8');
        // CORS Headers
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
        error_reporting(0);
        
        // Get parameters
        $device_id = $request->devices_id == "" ? "6361819" : $request->devices_id;
        $requested_date = $request->date == "" ? date("Y-m-d") : $request->date;
        
        $start_time = microtime(true);  // Track response time
        
        // ========================================================================
        // STEP 1: CHECK REDIS CACHE FIRST (Fastest path, <200ms response)
        // ========================================================================
        $cached_data = $this->getFromRedis($device_id, $requested_date);
        
        if ($cached_data !== null) {
            // Cache hit! Return immediately
            $response_time = (microtime(true) - $start_time) * 1000;  // Convert to milliseconds
            error_log("⚡ Cache HIT - Response time: " . round($response_time, 2) . "ms");
            
            $this->show_data("Data retrieved (from cache)", $cached_data);
            return;
        }
        
        // ========================================================================
        // STEP 2: FALLBACK TO JSON FILE (If Redis cache miss)
        // ========================================================================
        $file_data = $this->getFromFile($device_id, $requested_date);
        
        if ($file_data !== null) {
            // File found! Cache it to Redis for next time and return
            $this->setToRedis($device_id, $requested_date, $file_data);
            
            $response_time = (microtime(true) - $start_time) * 1000;
            error_log("📄 File fallback - Response time: " . round($response_time, 2) . "ms");
            
            $this->show_data("Data retrieved (from file)", $file_data);
            return;
        }
        
        // ========================================================================
        // STEP 3: LEGACY FALLBACK - QUERY DATABASE (If both Redis and file fail)
        // ========================================================================
        error_log("⚠️  Cache and file miss - Falling back to database query");
        
        // Original database query logic (from original get_charts_data_1)
        $digital_readings = DB::table("manual_readings")->where("type","Digital")->orderBy("id","asc")->get();
        $analog_readings = DB::table("manual_readings")->where("type","Analogue")->orderBy("id","asc")->get();
        
        // Fetch distinct times
        $get_times = DB::table("csv_data1")
            ->selectRaw("distinct(time)")
            ->where("device_id", $device_id)
            ->where("date", $requested_date)
            ->whereRaw("(actual_min > 0 and actual_max > 0 or actual_avg > 0 or actual_value = 1)")
            ->orderBy("time", "asc")
            ->get();
        
        // timestamps
        foreach($get_times as $get_time){
            $timestamps[] = strtotime($get_time->time);
        }
        
        // digital readings
        foreach($digital_readings as $digital_reading) { 
            $points_arr = [];
            foreach($get_times as $get_time){
                $value = DB::table("csv_data1")
                    ->where("device_id", $device_id)
                    ->where("date", $requested_date)
                    ->where("time", $get_time->time)
                    ->where("manual_readings_id",$digital_reading->id)
                    ->first();
                
                $points_arr[] = [
                    "time" => $get_time->time,
                    "value" => $value->actual_value ?? 0
                ];
            }
            $digital_arr[] = [
                "id"     => "D" . $digital_reading->number,
                "name"   => $digital_reading->name,
                "color"  => $digital_reading->stroke_color,
                "points" => $points_arr,
            ];
        }
        
        // analog readings
        foreach($analog_readings as $analog_reading){
            $points_arr = [];
            foreach($get_times as $get_time){
                $value = DB::table("csv_data1")
                    ->where("device_id", $device_id)
                    ->where("date", $requested_date)
                    ->where("time", $get_time->time)
                    ->where("manual_readings_id",$analog_reading->id)
                    ->first();
                
                $points_arr[] = [
                    "time" => $get_time->time,
                    "avg"  => $value->actual_avg > 0 ? $value->actual_avg : 0,
                    "max"  => $value->actual_max > 0 ? $value->actual_max : 0,
                    "min"  => $value->actual_min > 0 ? $value->actual_min : 0,
                ];
            }
            $analog_arr[] = [
                "id"     => "A" . $analog_reading->number,
                "name"   => $analog_reading->name,
                "color"  => $analog_reading->stroke_color,
                "points" => $points_arr,
            ];
        }
        
        // gps 
        foreach($get_times as $get_time){
            $devices_locations_data = DB::table("devices_locations")
                ->whereRaw("devices_id = '1' and date(date_time) = '{$requested_date}' and time(date_time) = '{$get_time->time}'")
                ->first();
            
            $time = strtotime($get_time->time);
            $gps_data[] = [
                "time" => date("H:i:s", $time),
                "lat"  => $devices_locations_data->latitude ?? "",
                "lng"  => $devices_locations_data->longitude ?? "",
            ];
        }
        
        $data = [
            "times" => $timestamps ?? [],
            "gpsPerSecond" => $gps_data ?? [],
            "digitalPerSecond" => $digital_arr ?? [],
            "analogPerSecond" => $analog_arr ?? []
        ];
        
        // Cache the database result to Redis for next time
        $this->setToRedis($device_id, $requested_date, $data);
        
        $response_time = (microtime(true) - $start_time) * 1000;
        error_log("🗄️  Database fallback - Response time: " . round($response_time, 2) . "ms");
        
        $this->show_data("Data retrieved", $data);
    }
    
    function manual_readings(){
        $digital_readings = DB::table("manual_readings")->where("type","Digital")->orderBy("id","asc")->get();
        $analog_readings = DB::table("manual_readings")->where("type","Analogue")->orderBy("id","asc")->get();
        $digital_reading_arr = null;
        $analog_reading_arr = null;
        
        foreach($digital_readings as $digital_reading){
            $digital_reading_arr[] = array(
                "id" => "D" . $digital_reading->number,
                "name" => $digital_reading->name
            );
        } 
        $data["digital_readings"] = $digital_reading_arr;
        
        foreach($analog_readings as $analog_reading){
            $analog_reading_arr[] = array(
                "id" => "A" . $analog_reading->number,
                "name" => $analog_reading->name
            );
        }
        $data["analog_readings"] = $analog_reading_arr;
        $this->show_data("Manual readings", $data);
    }
}

