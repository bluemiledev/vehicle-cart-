# Simple cPanel Update Guide

## 🎯 What You Need to Do

Replace/update your backend files in cPanel to add Redis caching.

---

## 📦 Step 1: Prepare Your Files

### Files You Need to Upload:

1. ✅ `backend/python_main/generate_json_cache.py` - NEW file
2. ✅ `backend/WebServices_redis.php` - NEW file (will replace old WebServices.php)
3. ✅ `backend/python_main/load_files.py` - UPDATED file
4. ✅ `backend/python_main/requirements.txt` - UPDATED file

**If you compressed the backend folder:**
- You should have a `backend_redis.zip` or similar file
- Extract it to see all files

---

## 📁 Step 2: Upload Files to cPanel

### Using cPanel File Manager:

1. **Login to cPanel**
   - Go to your hosting cPanel URL
   - Login with username/password

2. **Open File Manager**
   - Click the **"File Manager"** icon
   - Navigate to your website folder (usually `public_html` or `www`)

3. **Find Your Backend Folder**
   - Look for `backend` folder
   - Click to open it

4. **Upload Files**

   **Option A: Upload ZIP file**
   - Click **"Upload"** button at top
   - Select your `backend_redis.zip` file
   - Wait for upload to finish
   - Right-click the ZIP file → **"Extract"**
   - Delete the ZIP file after extraction

   **Option B: Upload Individual Files**
   - Click **"Upload"** button
   - Select all new/updated files
   - Upload them to correct folders:
     - `generate_json_cache.py` → `backend/python_main/`
     - `WebServices_redis.php` → `backend/`
     - `load_files.py` → `backend/python_main/`

---

## 🔄 Step 3: Replace Old PHP File

### Important: Backup First!

1. **Backup Current File:**
   - In File Manager, find `backend/WebServices.php`
   - Right-click → **"Copy"**
   - Rename copy to `WebServices.php.backup`

2. **Replace with New File:**
   - **Option 1:** Delete old `WebServices.php`, rename `WebServices_redis.php` to `WebServices.php`
   - **Option 2:** Open `WebServices_redis.php`, copy all code, paste into `WebServices.php`

---

## ⚙️ Step 4: Create .env File

1. **Create New File:**
   - In `backend/` folder, click **"New File"**
   - Name it: `.env` (with dot at beginning)

2. **Add Content:**
   - Right-click `.env` → **"Edit"**
   - Paste this (update if needed):
   ```
   REDIS_HOST=localhost
   REDIS_PORT=6379
   REDIS_PASSWORD=
   REDIS_DB=0
   DB_HOST=no-reply.com.au
   DB_USER=noreplycom_smartdatalinkuser
   DB_PASSWORD=Z2jq;6;Dm2E@
   DB_NAME=noreplycom_smartdatalink
   ```
   - Click **"Save Changes"**

3. **Set Permissions:**
   - Right-click `.env` → **"Change Permissions"**
   - Set to `600` (owner read/write only)

---

## ✅ Step 5: Verify Files

### Check These Files Exist:

```
backend/
├── WebServices.php          ← Should have Redis code now
├── .env                     ← Should exist with your settings
├── python_main/
│   ├── generate_json_cache.py  ← Should exist
│   ├── load_files.py           ← Should be updated
│   └── requirements.txt        ← Should be updated
└── data.json                ← Will be created automatically
```

---

## 🧪 Step 6: Test It

### Test Your API:

1. **Make a test request:**
   ```
   https://yourdomain.com/smart_data_link/get_charts_data_1?device_id=6361819&date=2025-01-15
   ```

2. **Should work normally:**
   - If Redis available → Fast response (<200ms)
   - If Redis not available → Uses file/database (still works!)

---

## ⚠️ Important Notes

### If Redis is Not Available:

✅ **Don't worry!** The system will automatically:
- Try Redis first
- If fails → Use JSON file
- If file missing → Use database

**Your API will still work!**

### If Python Scripts Don't Work:

✅ **Don't worry!** The PHP API will:
- Use Redis cache if available
- Fallback to file/database if needed

**Your API will still work!**

---

## 🆘 Quick Troubleshooting

### Problem: Files not uploading
- **Solution:** Check file size limits, try uploading one at a time

### Problem: .env file not saving
- **Solution:** Make sure filename starts with dot: `.env`

### Problem: API not responding
- **Solution:** Check error logs in cPanel → "Metrics" → "Errors"

### Problem: Redis connection failed
- **Solution:** Contact hosting provider to enable Redis, or system will use fallback

---

## 📋 Quick Checklist

Before finishing, make sure:

- [ ] All files uploaded to correct folders
- [ ] `WebServices.php` replaced with Redis version
- [ ] `.env` file created with settings
- [ ] API endpoint tested
- [ ] Old file backed up (WebServices.php.backup)

---

## 🎉 Done!

Your backend is now optimized with Redis caching!

**What happens now:**
- API requests will be faster (<200ms if Redis works)
- If Redis unavailable, system uses file/database automatically
- No breaking changes - everything works the same!

---

## 📞 Need Help?

1. Check cPanel error logs
2. Verify all files uploaded correctly
3. Test API endpoint
4. Contact hosting provider if Redis needed

