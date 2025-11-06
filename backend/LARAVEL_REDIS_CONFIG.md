# Laravel Redis Configuration (Alternative Method)

Since you're using Laravel, there's a better way to configure Redis using Laravel's built-in configuration system.

## 🎯 Method 1: Use Laravel Config File (Recommended)

### Step 1: Update Laravel Config File

**File:** `smart_data_link/config/database.php`

Find the `redis` section and update it:

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'), // or 'predis'

    'options' => [
        'cluster' => env('REDIS_CLUSTER', 'redis'),
        'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
    ],

    'default' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_DB', '0'),
        'read_timeout' => 2,
    ],
],
```

### Step 2: Update .env File

Add these to `smart_data_link/.env`:

```env
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
REDIS_DB=0
REDIS_CLIENT=phpredis
```

### Step 3: Update WebServices.php to Use Laravel Redis

Replace the Redis connection code in `WebServices.php`:

**In `__construct()` method:**
```php
// Instead of direct Redis connection, use Laravel's Redis facade
use Illuminate\Support\Facades\Redis;

// In constructor:
try {
    // Test Laravel Redis connection
    Redis::connection()->ping();
    $this->redis_available = true;
    error_log("✅ Laravel Redis connected successfully");
} catch (\Exception $e) {
    $this->redis_available = false;
    error_log("Redis connection error: " . $e->getMessage() . ". Falling back to file/database.");
}
```

**Update private properties:**
```php
private $redis_available = false; // Instead of $redis_client
```

**Update Redis methods to use Laravel Redis:**
```php
private function getFromRedis($device_id, $date) {
    if (!$this->redis_available) {
        return null;
    }
    
    try {
        $cache_key = "processed_data:{$device_id}:{$date}";
        $cached_data = Redis::get($cache_key);
        
        if ($cached_data !== null) {
            $data = json_decode($cached_data, true);
            if ($data !== null) {
                error_log("✅ Cache HIT from Redis (key: {$cache_key})");
                return $data;
            }
        }
        
        error_log("⚠️  Cache MISS in Redis (key: {$cache_key})");
        return null;
    } catch (\Exception $e) {
        error_log("Redis read error: " . $e->getMessage());
        return null;
    }
}

private function setToRedis($device_id, $date, $data) {
    if (!$this->redis_available) {
        return false;
    }
    
    try {
        $cache_key = "processed_data:{$device_id}:{$date}";
        $json_data = json_encode($data);
        Redis::setex($cache_key, $this->cache_expiry, $json_data);
        error_log("✅ Data cached to Redis (key: {$cache_key})");
        return true;
    } catch (\Exception $e) {
        error_log("Redis write error: " . $e->getMessage());
        return false;
    }
}
```

---

## 🎯 Method 2: Use Different Redis Host/Port

If Redis is on a different server, update `.env`:

```env
# If Redis is on different server
REDIS_HOST=your-redis-server.com
REDIS_PORT=6379
REDIS_PASSWORD=your_password_if_needed

# Or if using Redis Cloud/AWS ElastiCache
REDIS_HOST=your-cluster.redis.cache.amazonaws.com
REDIS_PORT=6379
REDIS_PASSWORD=your_auth_token
```

---

## 🎯 Method 3: Use Redis via Socket (Unix Socket)

Some shared hosting uses Unix sockets instead of TCP:

**Update .env:**
```env
REDIS_HOST=/var/run/redis/redis.sock
REDIS_PORT=0
```

**Update WebServices.php connection:**
```php
// In connectRedis() method:
if (file_exists($this->redis_host)) {
    // Unix socket
    $connected = $redis->connect($this->redis_host);
} else {
    // TCP connection
    $connected = $redis->connect($this->redis_host, $this->redis_port, 2);
}
```

---

## 🎯 Method 4: Use Predis Client (Alternative)

If `phpredis` extension has issues, use Predis (pure PHP):

**Install via Composer:**
```bash
composer require predis/predis
```

**Update config/database.php:**
```php
'client' => env('REDIS_CLIENT', 'predis'),
```

**Update .env:**
```env
REDIS_CLIENT=predis
```

---

## 🎯 Method 5: Use Cache Layer (Laravel Cache)

Instead of direct Redis, use Laravel's Cache facade:

**In WebServices.php:**
```php
use Illuminate\Support\Facades\Cache;

private function getFromRedis($device_id, $date) {
    $cache_key = "processed_data:{$device_id}:{$date}";
    
    try {
        $data = Cache::get($cache_key);
        if ($data !== null) {
            error_log("✅ Cache HIT (key: {$cache_key})");
            return $data;
        }
        return null;
    } catch (\Exception $e) {
        error_log("Cache read error: " . $e->getMessage());
        return null;
    }
}

private function setToRedis($device_id, $date, $data) {
    $cache_key = "processed_data:{$device_id}:{$date}";
    
    try {
        Cache::put($cache_key, $data, $this->cache_expiry); // seconds
        error_log("✅ Data cached (key: {$cache_key})");
        return true;
    } catch (\Exception $e) {
        error_log("Cache write error: " . $e->getMessage());
        return false;
    }
}
```

**config/cache.php should have:**
```php
'default' => env('CACHE_DRIVER', 'redis'),
```

**This method:**
- ✅ Works with any cache driver (Redis, Memcached, File, etc.)
- ✅ More Laravel-standard
- ✅ Easier to switch cache backends

---

## 🎯 Method 6: Check Hosting Provider's Redis Settings

Many shared hosting providers have specific Redis settings:

**Check cPanel for:**
- Redis section
- Cache section
- Application Settings
- Or contact support for Redis connection details

**Common hosting Redis configurations:**
- Host: `127.0.0.1` (localhost)
- Host: `redis.yourdomain.com`
- Host: `localhost` (but different port)
- Socket: `/var/run/redis/redis.sock`

---

## 📋 Quick Comparison

| Method | Pros | Cons |
|--------|------|------|
| **Direct Redis** (current) | Full control | May not work on all hosts |
| **Laravel Redis Facade** | Standard, tested | Requires config setup |
| **Laravel Cache** | Most flexible | Slight abstraction layer |
| **Predis** | Pure PHP, no extension | Slightly slower |

---

## ✅ Recommended: Use Laravel Cache (Method 5)

**Why?**
- ✅ Works with any cache backend
- ✅ Laravel standard approach
- ✅ Easy to configure
- ✅ Can switch to file/memcached if Redis unavailable
- ✅ No direct Redis dependency

**Implementation:** See Method 5 above.

---

## 🆘 Still Having Issues?

1. **Check hosting documentation** for Redis settings
2. **Contact hosting support** for Redis connection details
3. **Use Laravel Cache** (Method 5) - most compatible
4. **Continue without Redis** - system works fine!

Your system is fully functional even without Redis caching. It's an optimization, not a requirement.


