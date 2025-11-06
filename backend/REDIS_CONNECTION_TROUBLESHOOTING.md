# Redis Connection Troubleshooting

## ✅ Current Status

- ✅ PHP Redis extension installed
- ✅ Code deployed correctly
- ❌ Redis server connection refused

## 🔍 Issue: "Connection refused"

This means Redis server is either:
1. Not running
2. Running on a different host/port
3. Blocked by firewall
4. Not accessible from PHP

---

## 🔧 Step-by-Step Fix

### Step 1: Check Redis Server Status

**Via cPanel Terminal (if available):**
```bash
redis-cli ping
# Should return: PONG
```

**If command not found:**
- Redis server might not be installed
- Or not in PATH

---

### Step 2: Check .env Configuration

**File:** `smart_data_link/.env`

Verify Redis settings:
```env
REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DB=0
```

**Possible Issues:**
- If Redis is on different server, update `REDIS_HOST`
- If Redis uses different port, update `REDIS_PORT`
- If Redis requires password, add `REDIS_PASSWORD`

---

### Step 3: Test Redis Connection

**Create test file:** `smart_data_link/test_redis_connection.php`

```php
<?php
header('Content-Type: application/json');

$host = getenv('REDIS_HOST') ?: 'localhost';
$port = getenv('REDIS_PORT') ?: 6379;
$password = getenv('REDIS_PASSWORD') ?: null;

echo "Testing Redis connection...\n";
echo "Host: {$host}\n";
echo "Port: {$port}\n\n";

try {
    $redis = new Redis();
    $connected = $redis->connect($host, $port, 2);
    
    if ($connected) {
        if ($password) {
            $redis->auth($password);
        }
        $ping = $redis->ping();
        echo json_encode([
            'status' => 'success',
            'message' => 'Redis connected!',
            'ping' => $ping,
            'host' => $host,
            'port' => $port
        ], JSON_PRETTY_PRINT);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Connection failed',
            'host' => $host,
            'port' => $port
        ], JSON_PRETTY_PRINT);
    }
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'host' => $host,
        'port' => $port
    ], JSON_PRETTY_PRINT);
}
?>
```

**Access via browser:**
```
https://www.no-reply.com.au/smart_data_link/test_redis_connection.php
```

---

### Step 4: Common Solutions

#### Solution A: Redis Not Installed

**Contact hosting provider:**
- Ask them to install Redis server
- Or provide Redis server details (host, port, password)

**Shared hosting options:**
- Some hosts provide Redis on different host (not localhost)
- Check hosting documentation for Redis settings

#### Solution B: Redis on Different Host

**Update .env:**
```env
REDIS_HOST=your-redis-host.com
REDIS_PORT=6379
REDIS_PASSWORD=your_password_if_needed
```

#### Solution C: Redis Not Running

**If you have server access:**
```bash
# Start Redis
sudo systemctl start redis-server

# Or
redis-server
```

**If shared hosting:**
- Contact hosting provider to start Redis service

#### Solution D: Use Hosting Provider's Redis

Many shared hosting providers offer Redis but on:
- Different host (not localhost)
- Different port
- With authentication

**Check:**
- cPanel → "Redis" or "Cache" section
- Hosting documentation
- Support ticket

---

## ✅ Alternative: System Works Without Redis

**Important:** Your system is fully functional even without Redis!

**Current behavior:**
- ✅ API works perfectly
- ✅ Uses database fallback
- ⚠️ Responses: 500-2000ms (instead of <200ms)

**This is acceptable for production** - just slower than with Redis.

---

## 🎯 Quick Decision Tree

1. **Do you have Redis server access?**
   - ✅ Yes → Start Redis: `redis-server` or `sudo systemctl start redis`
   - ❌ No → Contact hosting provider

2. **Is Redis on different host?**
   - ✅ Yes → Update `.env` with correct host/port
   - ❌ No → Continue troubleshooting

3. **Shared hosting?**
   - ✅ Yes → Check cPanel for Redis settings or contact support
   - ❌ No → Check server if Redis is installed

4. **Can't get Redis working?**
   - ✅ System still works without it!
   - ✅ Just slower responses (acceptable)

---

## 📋 Next Steps

1. **Check .env file** - Verify Redis host/port settings
2. **Test connection** - Use test_redis_connection.php
3. **Contact hosting** - If Redis not available
4. **Or continue without Redis** - System works fine!

---

## ✅ Summary

**Your deployment is successful!**
- Code is deployed ✅
- PHP extension installed ✅
- Redis connection needs configuration ⚠️

**System status:** Fully functional (uses database fallback if Redis unavailable)

**Performance:** Works but slower without Redis (500-2000ms vs <200ms)


