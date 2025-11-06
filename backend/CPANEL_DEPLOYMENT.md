# cPanel Deployment Guide - Redis Caching Backend

This guide explains how to deploy the Redis-optimized backend to your shared hosting cPanel.

## 📦 Step 1: Prepare Files for Upload

### Option A: Upload Individual Files (Recommended)

1. **Compress the backend folder:**
   ```bash
   # On your local machine, compress the backend folder
   zip -r backend_redis.zip backend/
   ```

2. **Files to upload:**
   - `backend/python_main/generate_json_cache.py` (NEW)
   - `backend/WebServices_redis.php` (NEW - replace old WebServices.php)
   - `backend/python_main/load_files.py` (UPDATED)
   - `backend/python_main/requirements.txt` (UPDATED)
   - `backend/env.example` (NEW - for reference)

### Option B: Upload Entire Backend Folder

If you have FTP/SFTP access, upload the entire `backend` folder structure.

## 📁 Step 2: Upload Files via cPanel File Manager

### Method 1: Using cPanel File Manager

1. **Login to cPanel**
   - Go to your hosting provider's cPanel URL
   - Login with your credentials

2. **Navigate to File Manager**
   - Click "File Manager" icon
   - Navigate to your website root (usually `public_html` or `www`)

3. **Upload Files**
   - Click "Upload" button
   - Select your `backend_redis.zip` file
   - Wait for upload to complete

4. **Extract Files**
   - Right-click on `backend_redis.zip`
   - Select "Extract"
   - Choose extraction location (usually `public_html/backend` or your existing backend folder)

5. **Verify File Structure**
   ```
   backend/
   ├── python_main/
   │   ├── generate_json_cache.py  ← NEW
   │   ├── load_files.py            ← UPDATED
   │   ├── requirements.txt         ← UPDATED
   │   └── ... (other existing files)
   ├── WebServices_redis.php        ← NEW (replace old WebServices.php)
   ├── env.example                  ← NEW
   └── data.json                    ← Will be created automatically
   ```

### Method 2: Using FTP/SFTP Client

1. **Connect via FTP**
   - Use FileZilla, WinSCP, or similar
   - Connect to your hosting server
   - Navigate to your backend directory

2. **Upload Files**
   - Drag and drop new/modified files
   - Ensure folder structure matches

## 🔧 Step 3: Replace Old PHP File

### Important: Backup First!

1. **Backup existing file:**
   ```bash
   # In cPanel File Manager:
   # 1. Navigate to backend/
   # 2. Right-click WebServices.php
   # 3. Select "Copy"
   # 4. Rename to WebServices.php.backup
   ```

2. **Replace with new file:**
   - Delete or rename `WebServices.php` to `WebServices.php.old`
   - Rename `WebServices_redis.php` to `WebServices.php`
   - OR: Merge the Redis caching code into your existing `WebServices.php`

## ⚙️ Step 4: Configure Environment Variables in cPanel

### Option A: Create .env File (Recommended)

1. **In cPanel File Manager:**
   - Navigate to `backend/` folder
   - Click "New File"
   - Name it `.env` (with the dot at the beginning)

2. **Edit .env file:**
   - Right-click `.env` → "Edit"
   - Add your Redis configuration:
   ```env
   # Redis Configuration
   REDIS_HOST=localhost
   REDIS_PORT=6379
   REDIS_PASSWORD=
   REDIS_DB=0
   
   # Database Configuration
   DB_HOST=no-reply.com.au
   DB_USER=noreplycom_smartdatalinkuser
   DB_PASSWORD=Z2jq;6;Dm2E@
   DB_NAME=noreplycom_smartdatalink
   ```

3. **Set Permissions:**
   - Right-click `.env` → "Change Permissions"
   - Set to `600` (read/write for owner only)
   - This prevents others from reading your credentials

### Option B: Set PHP Environment Variables

If `.env` doesn't work, set environment variables in PHP:

1. **Edit `WebServices.php` constructor:**
   ```php
   function __construct() {
       // Set environment variables directly
       putenv('REDIS_HOST=localhost');
       putenv('REDIS_PORT=6379');
       // ... etc
   }
   ```

### Option C: Use cPanel's Environment Variables

1. **In cPanel:**
   - Go to "Advanced" → "Environment Variables"
   - Add each variable:
     - `REDIS_HOST` = `localhost`
     - `REDIS_PORT` = `6379`
     - etc.

## 🔴 Step 5: Check Redis Availability

### Check if Redis is Installed

1. **Via cPanel Terminal (if available):**
   ```bash
   redis-cli ping
   # Should return: PONG
   ```

2. **Check PHP Redis Extension:**
   - In cPanel, go to "Select PHP Version"
   - Click "Extensions"
   - Look for "redis" extension
   - Enable it if available

3. **If Redis Extension Missing:**
   - Contact your hosting provider to install PHP Redis extension
   - OR: The system will automatically fallback to JSON file/database

### Test Redis Connection

Create a test file `backend/test_redis.php`:

```php
<?php
if (class_exists('Redis')) {
    try {
        $redis = new Redis();
        $redis->connect('localhost', 6379, 2);
        $redis->ping();
        echo "✅ Redis connected successfully!";
    } catch (Exception $e) {
        echo "❌ Redis connection failed: " . $e->getMessage();
        echo "<br>System will use file/database fallback.";
    }
} else {
    echo "⚠️ PHP Redis extension not installed.";
    echo "<br>System will use file/database fallback.";
}
?>
```

Access via browser: `https://yourdomain.com/backend/test_redis.php`

## 🐍 Step 6: Configure Python Scripts

### Check Python Version

1. **Via cPanel Terminal:**
   ```bash
   python3 --version
   # or
   python --version
   ```

### Install Python Dependencies

1. **Via cPanel Terminal:**
   ```bash
   cd ~/backend/python_main
   pip3 install -r requirements.txt
   ```

2. **If pip not available:**
   - Contact hosting provider to enable pip
   - OR: Install packages manually

### Set Python Script Permissions

1. **In cPanel File Manager:**
   - Navigate to `backend/python_main/`
   - Right-click `generate_json_cache.py`
   - "Change Permissions" → Set to `755` (executable)

### Configure Cron Job (Auto-run Python Script)

1. **In cPanel:**
   - Go to "Advanced" → "Cron Jobs"

2. **Add Cron Job:**
   ```bash
   # Run every 5 minutes (after CSV processing)
   */5 * * * * cd /home/username/backend/python_main && /usr/bin/python3 generate_json_cache.py >> /home/username/logs/python_cache.log 2>&1
   ```

   **Replace:**
   - `username` with your cPanel username
   - `/usr/bin/python3` with your Python path (check with `which python3`)

## 📝 Step 7: Update PHP Code (If Needed)

### If Your PHP Framework Uses Different Structure

If `WebServices.php` is part of Laravel or another framework:

1. **Locate your controller:**
   - Usually in `app/Http/Controllers/` or similar

2. **Merge Redis code:**
   - Copy Redis connection methods from `WebServices_redis.php`
   - Add to your existing controller
   - Update `get_charts_data_1` method

3. **Update Routes:**
   - Ensure routes point to updated controller

## ✅ Step 8: Test the Deployment

### Test 1: Check File Structure
```
✅ backend/python_main/generate_json_cache.py exists
✅ backend/WebServices.php has Redis caching code
✅ backend/.env file exists with correct settings
✅ backend/data.json can be created (check permissions)
```

### Test 2: Test API Endpoint

1. **Make API Request:**
   ```
   GET https://yourdomain.com/smart_data_link/get_charts_data_1?device_id=6361819&date=2025-01-15
   ```

2. **Check Response:**
   - Should return JSON data
   - Response time should be <200ms (if Redis working)

### Test 3: Check Error Logs

1. **In cPanel:**
   - Go to "Metrics" → "Errors"
   - Look for Redis-related messages:
     - `✅ Cache HIT from Redis` - Working!
     - `⚠️ Cache MISS` - Redis not connected or expired
     - `📄 File fallback` - Using JSON file
     - `🗄️ Database fallback` - Using database

## 🔍 Step 9: Verify Cache is Working

### Check Redis Keys (if terminal available)

```bash
redis-cli
> KEYS processed_data:*
```

Should show cached keys like:
```
processed_data:6361819:2025-01-15
```

### Check JSON File Created

1. **In cPanel File Manager:**
   - Navigate to `backend/`
   - Check if `data.json` exists
   - Should be created after Python script runs

## 🆘 Troubleshooting

### Issue: Redis Connection Failed

**Solution:**
- Check if Redis service is running on shared hosting
- Contact hosting provider to enable Redis
- System will automatically fallback to file/database

### Issue: Python Script Not Running

**Solution:**
1. Check Python path: `which python3`
2. Check file permissions: `chmod +x generate_json_cache.py`
3. Check cron job syntax
4. Check error logs: `tail -f logs/python_cache.log`

### Issue: PHP Redis Extension Missing

**Solution:**
1. Contact hosting provider to install
2. System will automatically use file/database fallback
3. No code changes needed

### Issue: Permission Denied

**Solution:**
```bash
# Set correct permissions
chmod 755 backend/python_main/generate_json_cache.py
chmod 644 backend/data.json
chmod 600 backend/.env
```

### Issue: .env File Not Loading

**Solution:**
1. Check file exists: `ls -la backend/.env`
2. Check file permissions: `chmod 600 backend/.env`
3. If still not working, set environment variables directly in PHP code

## 📋 Checklist

Before going live, verify:

- [ ] All files uploaded to correct locations
- [ ] `WebServices.php` replaced/updated with Redis code
- [ ] `.env` file created with correct Redis settings
- [ ] PHP Redis extension enabled (or fallback working)
- [ ] Python dependencies installed
- [ ] Cron job configured (if using scheduled updates)
- [ ] File permissions set correctly
- [ ] API endpoint tested and responding
- [ ] Error logs checked for issues
- [ ] Cache working (check response times)

## 🎯 Quick Reference

### File Locations in cPanel
```
/home/username/
├── public_html/          (or www/)
│   └── backend/
│       ├── WebServices.php
│       ├── .env
│       ├── data.json
│       └── python_main/
│           ├── generate_json_cache.py
│           └── load_files.py
```

### Important Commands
```bash
# Check Redis
redis-cli ping

# Check Python
python3 --version

# Check PHP Redis extension
php -m | grep redis

# Check file permissions
ls -la backend/

# Test Python script
cd backend/python_main
python3 generate_json_cache.py 6361819 2025-01-15
```

## 📞 Support

If you encounter issues:

1. Check error logs in cPanel
2. Verify all file paths are correct
3. Test Redis connection separately
4. Contact hosting provider for Redis/PHP extension support
5. System will work with file/database fallback if Redis unavailable

---

**Remember:** The system is designed to work even if Redis is unavailable. It will automatically fallback to JSON file or database, so your API will continue to function.


