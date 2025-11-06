# Quick Start Guide - Redis Caching Setup

## 🚀 5-Minute Setup

### Step 1: Install Redis
```bash
# Ubuntu/Debian
sudo apt-get install redis-server
sudo systemctl start redis-server

# macOS
brew install redis
brew services start redis
```

### Step 2: Install PHP Redis Extension
```bash
# Linux
sudo apt-get install php-redis

# macOS
pecl install redis
```

Add to `php.ini`:
```ini
extension=redis.so
```

### Step 3: Install Python Dependencies
```bash
cd backend/python_main
pip install -r requirements.txt
```

### Step 4: Configure Environment
```bash
cd backend
cp env.example .env
# Edit .env with your Redis settings
```

### Step 5: Deploy PHP Changes
Replace `WebServices.php` with `WebServices_redis.php` (or merge the changes manually).

## ✅ Testing

### Test Redis Connection
```bash
redis-cli ping
# Should return: PONG
```

### Test Python Script
```bash
cd backend/python_main
python generate_json_cache.py 6361819 2025-01-15
```

### Test PHP API
Make an API request and check error logs for:
- `✅ Cache HIT from Redis` - Working!
- `⚠️ Cache MISS` - Check Redis connection

## 📊 Expected Results

- **Cache Hit Response**: <200ms ⚡
- **File Fallback**: ~50-100ms 📄
- **Database Fallback**: ~500-2000ms 🗄️

## 📚 Documentation

- `REDIS_SETUP.md` - Full installation guide
- `PERFORMANCE_EXPLANATION.md` - How Redis improves performance

## 🆘 Troubleshooting

**Redis not connecting?**
- Check: `redis-cli ping`
- Verify `.env` settings
- PHP will auto-fallback to file/database

**Cache not updating?**
- Python script runs after CSV processing
- Check: `redis-cli KEYS processed_data:*`

**PHP extension missing?**
- Check: `php -m | grep redis`
- System auto-falls back to file/database


