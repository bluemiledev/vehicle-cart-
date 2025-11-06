# Redis Caching Setup Guide

## Overview

This backend has been optimized with Redis caching to achieve **ultra-fast API responses (<200ms)**. The system implements a three-tier fallback strategy:

1. **Redis Cache** (Primary) - Fastest, <200ms response
2. **JSON File** (Fallback) - Medium speed, disk-based
3. **Database Query** (Legacy Fallback) - Slowest, but always works

## Architecture

```
┌─────────────────┐
│  CSV Files      │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Python Script  │
│  (load_files.py)│
└────────┬────────┘
         │
         ├──► Updates Database
         │
         ├──► Generates JSON → backend/data.json
         │
         └──► Caches to Redis (60s expiry)
         
┌─────────────────┐
│   PHP API       │
│ (WebServices.php)│
└────────┬────────┘
         │
         ├──► 1. Check Redis (fastest)
         │
         ├──► 2. Check JSON file (fallback)
         │
         └──► 3. Query database (legacy)
```

## Installation

### 1. Install Redis Server

**Linux (Ubuntu/Debian):**
```bash
sudo apt-get update
sudo apt-get install redis-server
sudo systemctl start redis-server
sudo systemctl enable redis-server
```

**macOS:**
```bash
brew install redis
brew services start redis
```

**Windows:**
- Download Redis from: https://github.com/microsoftarchive/redis/releases
- Or use WSL2: `wsl sudo apt-get install redis-server`

### 2. Install PHP Redis Extension

**Linux:**
```bash
sudo apt-get install php-redis
# Or compile from source:
pecl install redis
```

**macOS:**
```bash
pecl install redis
```

**Windows:**
- Download `php_redis.dll` from PECL and add to PHP extensions directory

After installation, add to `php.ini`:
```ini
extension=redis.so  # Linux/macOS
extension=php_redis.dll  # Windows
```

### 3. Install Python Dependencies

```bash
cd backend/python_main
pip install -r requirements.txt
```

This will install:
- `redis` - Python Redis client
- `python-dotenv` - Environment variable management
- Other existing dependencies

### 4. Configure Environment Variables

Copy the example file and configure:
```bash
cd backend
cp .env.example .env
```

Edit `.env` with your Redis settings:
```env
REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_PASSWORD=your_password_if_set
REDIS_DB=0
```

## Usage

### Python Script (Generate JSON & Cache to Redis)

The Python script automatically runs after CSV processing, but you can also run it manually:

```bash
cd backend/python_main
python generate_json_cache.py [device_id] [date]
```

**Example:**
```bash
python generate_json_cache.py 6361819 2025-01-15
```

**What it does:**
1. Connects to database
2. Generates chart data (matching PHP API structure)
3. Saves JSON to `backend/data.json`
4. Caches to Redis with 60-second expiry

### PHP API (Uses Redis Cache Automatically)

The PHP API (`WebServices.php`) automatically uses Redis caching. No code changes needed in your routes - just replace the old `WebServices.php` with `WebServices_redis.php` or merge the changes.

**Cache Flow:**
1. API receives request for `device_id` and `date`
2. Checks Redis cache first (key: `processed_data:device_id:date`)
3. If cache hit → returns in <200ms
4. If cache miss → reads JSON file
5. If file missing → queries database (legacy)
6. Caches result to Redis for next request

## Performance Metrics

### Expected Response Times

- **Redis Cache Hit**: <200ms ⚡ (Ultra-fast)
- **JSON File Fallback**: ~50-100ms 📄 (Fast)
- **Database Query**: ~500-2000ms 🗄️ (Slow, legacy)

### Cache Expiry

- **Cache TTL**: 60 seconds
- **Reason**: Data updates every minute from CSV processing
- **Auto-refresh**: Python script regenerates cache after each CSV batch

## Error Handling

The system gracefully handles failures:

1. **Redis Connection Fails**: Automatically falls back to JSON file
2. **JSON File Missing**: Falls back to database query
3. **Database Query Fails**: Returns error (existing behavior)

All errors are logged to PHP error log for debugging.

## Monitoring

### Check Redis Connection

```bash
redis-cli ping
# Should return: PONG
```

### View Cached Keys

```bash
redis-cli
> KEYS processed_data:*
```

### Check Cache Hit Rate

Check PHP error logs for messages like:
- `✅ Cache HIT from Redis` - Cache working
- `⚠️ Cache MISS in Redis` - Cache miss, using fallback
- `📄 File fallback` - Using JSON file
- `🗄️ Database fallback` - Using database query

## Troubleshooting

### Redis Not Connecting

1. Check Redis is running: `redis-cli ping`
2. Verify host/port in `.env` file
3. Check firewall rules
4. PHP will automatically fallback to file/database

### Cache Not Updating

1. Verify Python script runs after CSV processing
2. Check Redis keys: `redis-cli KEYS processed_data:*`
3. Verify cache expiry: `redis-cli TTL processed_data:6361819:2025-01-15`

### PHP Redis Extension Missing

1. Check extension loaded: `php -m | grep redis`
2. If missing, install PHP Redis extension (see Installation)
3. System will fallback to file/database automatically

## API Response Structure

The API response structure remains **unchanged** - all optimizations are transparent:

```json
{
  "status": "1",
  "message": "Data retrieved (from cache)",
  "data": {
    "times": [1234567890, ...],
    "gpsPerSecond": [...],
    "digitalPerSecond": [...],
    "analogPerSecond": [...]
  }
}
```

## Benefits

1. **Ultra-Fast Responses**: <200ms on cache hits
2. **Reduced Database Load**: Cache eliminates repeated queries
3. **Graceful Degradation**: Falls back to file/database if Redis fails
4. **Auto-Refresh**: Cache updates automatically after CSV processing
5. **Zero Breaking Changes**: API response structure unchanged

## Next Steps

1. Replace `WebServices.php` with `WebServices_redis.php` (or merge changes)
2. Configure `.env` file with Redis settings
3. Install Redis server and PHP extension
4. Test API endpoints - should see <200ms response times
5. Monitor error logs for cache hit/miss rates


