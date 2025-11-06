#!/usr/bin/env python3
"""
Python script to convert database data to JSON and cache in Redis.

This script:
1. Queries the database for chart data (similar to PHP get_charts_data_1)
2. Generates JSON response matching the PHP API structure
3. Saves JSON to backend/data.json (fallback storage)
4. Caches processed data in Redis with 60-second expiry

Run this script after CSV processing to update both file and Redis cache.
"""

import json
import os
import sys
from datetime import datetime
import mysql.connector
import redis
from dotenv import load_dotenv

# Load environment variables from .env file
load_dotenv()

# ============================================================================
# REDIS CONFIGURATION - Read from environment variables
# ============================================================================
REDIS_HOST = os.getenv('REDIS_HOST', 'localhost')
REDIS_PORT = int(os.getenv('REDIS_PORT', 6379))
REDIS_PASSWORD = os.getenv('REDIS_PASSWORD', None)
REDIS_DB = int(os.getenv('REDIS_DB', 0))
REDIS_KEY_PREFIX = 'processed_data'  # Key prefix for cached data
CACHE_EXPIRY_SECONDS = 60  # 60 second cache expiry

# ============================================================================
# DATABASE CONFIGURATION
# ============================================================================
DB_HOST = os.getenv('DB_HOST', 'no-reply.com.au')
DB_USER = os.getenv('DB_USER', 'noreplycom_smartdatalinkuser')
DB_PASSWORD = os.getenv('DB_PASSWORD', 'Z2jq;6;Dm2E@')
DB_NAME = os.getenv('DB_NAME', 'noreplycom_smartdatalink')

# ============================================================================
# FILE CONFIGURATION
# ============================================================================
# Get the backend directory (parent of python_main)
BACKEND_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
JSON_FILE_PATH = os.path.join(BACKEND_DIR, 'data.json')


def connect_redis():
    """
    Connect to Redis server.
    
    Returns:
        redis.Redis: Redis connection object or None if connection fails
    """
    try:
        r = redis.Redis(
            host=REDIS_HOST,
            port=REDIS_PORT,
            password=REDIS_PASSWORD,
            db=REDIS_DB,
            decode_responses=True,  # Automatically decode responses to strings
            socket_connect_timeout=2,  # 2 second connection timeout
            socket_timeout=2
        )
        # Test connection
        r.ping()
        print(f"✅ Connected to Redis at {REDIS_HOST}:{REDIS_PORT}")
        return r
    except (redis.ConnectionError, redis.TimeoutError, Exception) as e:
        print(f"⚠️  Redis connection failed: {e}")
        print("   Continuing without Redis (will use file fallback only)")
        return None


def connect_database():
    """
    Connect to MySQL database.
    
    Returns:
        tuple: (connection, cursor) or (None, None) if connection fails
    """
    try:
        conn = mysql.connector.connect(
            host=DB_HOST,
            user=DB_USER,
            password=DB_PASSWORD,
            database=DB_NAME
        )
        cursor = conn.cursor(dictionary=True)  # Return results as dictionaries
        print(f"✅ Connected to database: {DB_NAME}")
        return conn, cursor
    except Exception as e:
        print(f"❌ Database connection failed: {e}")
        return None, None


def generate_chart_data(device_id, requested_date, cursor):
    """
    Generate chart data from database (matching PHP get_charts_data_1 structure).
    
    Args:
        device_id (str): Device ID to query
        requested_date (str): Date in YYYY-MM-DD format
        cursor: Database cursor
        
    Returns:
        dict: Chart data matching PHP API response structure
    """
    try:
        # Fetch digital and analog readings
        cursor.execute("""
            SELECT * FROM manual_readings 
            WHERE type = 'Digital' 
            ORDER BY id ASC
        """)
        digital_readings = cursor.fetchall()
        
        cursor.execute("""
            SELECT * FROM manual_readings 
            WHERE type = 'Analogue' 
            ORDER BY id ASC
        """)
        analog_readings = cursor.fetchall()
        
        # Fetch distinct times for the device and date
        cursor.execute("""
            SELECT DISTINCT(time) 
            FROM csv_data1 
            WHERE device_id = %s 
            AND date = %s 
            AND (actual_min > 0 AND actual_max > 0 OR actual_avg > 0 OR actual_value = 1)
            ORDER BY time ASC
        """, (device_id, requested_date))
        time_results = cursor.fetchall()
        
        timestamps = []
        get_times = []
        for row in time_results:
            time_str = row['time']
            get_times.append(time_str)
            # Convert time to timestamp
            try:
                dt = datetime.strptime(f"{requested_date} {time_str}", "%Y-%m-%d %H:%M:%S")
                timestamps.append(int(dt.timestamp()))
            except:
                # Fallback if time parsing fails
                timestamps.append(0)
        
        # Process digital readings
        digital_arr = []
        for digital_reading in digital_readings:
            points_arr = []
            for get_time in get_times:
                cursor.execute("""
                    SELECT actual_value 
                    FROM csv_data1 
                    WHERE device_id = %s 
                    AND date = %s 
                    AND time = %s 
                    AND manual_readings_id = %s
                    LIMIT 1
                """, (device_id, requested_date, get_time, digital_reading['id']))
                result = cursor.fetchone()
                points_arr.append({
                    "time": str(get_time),
                    "value": result['actual_value'] if result and result['actual_value'] else 0
                })
            
            digital_arr.append({
                "id": f"D{digital_reading['number']}",
                "name": digital_reading['name'],
                "color": digital_reading['stroke_color'],
                "points": points_arr
            })
        
        # Process analog readings
        analog_arr = []
        for analog_reading in analog_readings:
            points_arr = []
            for get_time in get_times:
                cursor.execute("""
                    SELECT actual_avg, actual_max, actual_min 
                    FROM csv_data1 
                    WHERE device_id = %s 
                    AND date = %s 
                    AND time = %s 
                    AND manual_readings_id = %s
                    LIMIT 1
                """, (device_id, requested_date, get_time, analog_reading['id']))
                result = cursor.fetchone()
                
                points_arr.append({
                    "time": str(get_time),
                    "avg": result['actual_avg'] if result and result['actual_avg'] > 0 else 0,
                    "max": result['actual_max'] if result and result['actual_max'] > 0 else 0,
                    "min": result['actual_min'] if result and result['actual_min'] > 0 else 0
                })
            
            analog_arr.append({
                "id": f"A{analog_reading['number']}",
                "name": analog_reading['name'],
                "color": analog_reading['stroke_color'],
                "points": points_arr
            })
        
        # Process GPS data
        gps_data = []
        for get_time in get_times:
            cursor.execute("""
                SELECT latitude, longitude 
                FROM devices_locations 
                WHERE devices_id = 1 
                AND DATE(date_time) = %s 
                AND TIME(date_time) = %s
                LIMIT 1
            """, (requested_date, get_time))
            location_result = cursor.fetchone()
            
            if location_result:
                try:
                    dt = datetime.strptime(f"{requested_date} {get_time}", "%Y-%m-%d %H:%M:%S")
                    time_str = dt.strftime("%H:%M:%S")
                except:
                    time_str = str(get_time)
                
                gps_data.append({
                    "time": time_str,
                    "lat": location_result['latitude'] if location_result else "",
                    "lng": location_result['longitude'] if location_result else ""
                })
        
        # Build response data matching PHP structure
        data = {
            "times": timestamps,
            "gpsPerSecond": gps_data,
            "digitalPerSecond": digital_arr,
            "analogPerSecond": analog_arr
        }
        
        return data
    
    except Exception as e:
        print(f"❌ Error generating chart data: {e}")
        raise


def save_json_file(data, device_id, requested_date):
    """
    Save JSON data to file (fallback storage).
    
    Args:
        data (dict): Chart data to save
        device_id (str): Device ID
        requested_date (str): Date
    """
    try:
        # Create backend directory if it doesn't exist
        os.makedirs(os.path.dirname(JSON_FILE_PATH), exist_ok=True)
        
        # Save with metadata
        json_data = {
            "device_id": device_id,
            "date": requested_date,
            "generated_at": datetime.now().isoformat(),
            "data": data
        }
        
        with open(JSON_FILE_PATH, 'w', encoding='utf-8') as f:
            json.dump(json_data, f, indent=2, ensure_ascii=False)
        
        print(f"✅ JSON saved to: {JSON_FILE_PATH}")
        return True
    except Exception as e:
        print(f"❌ Failed to save JSON file: {e}")
        return False


def cache_to_redis(redis_client, data, device_id, requested_date):
    """
    Cache processed data to Redis.
    
    Args:
        redis_client: Redis connection object
        data (dict): Chart data to cache
        device_id (str): Device ID
        requested_date (str): Date
        
    Returns:
        bool: True if successful, False otherwise
    """
    if redis_client is None:
        return False
    
    try:
        # Create cache key: processed_data:device_id:date
        cache_key = f"{REDIS_KEY_PREFIX}:{device_id}:{requested_date}"
        
        # Serialize data to JSON string
        json_string = json.dumps(data, ensure_ascii=False)
        
        # Store in Redis with expiry
        redis_client.setex(
            cache_key,
            CACHE_EXPIRY_SECONDS,
            json_string
        )
        
        print(f"✅ Data cached to Redis (key: {cache_key}, expiry: {CACHE_EXPIRY_SECONDS}s)")
        return True
    except Exception as e:
        print(f"⚠️  Failed to cache to Redis: {e}")
        return False


def cache_dates_for_all_vehicles(redis_client, cursor):
    """
    Pre-cache dates for all vehicles to improve API response times.
    
    This function queries all vehicles and their available dates,
    then caches them to Redis for fast retrieval.
    """
    if redis_client is None:
        return
    
    try:
        print("🔄 Pre-caching dates for all vehicles...")
        
        # Get all active vehicles
        cursor.execute("""
            SELECT v.id as vehicles_id, d.serial_no as device_id
            FROM vehicles v
            INNER JOIN devices d ON d.vehicles_id = v.id
            WHERE v.status = 'Active'
        """)
        vehicles = cursor.fetchall()
        
        cached_count = 0
        for vehicle in vehicles:
            vehicles_id = vehicle['vehicles_id']
            device_id = vehicle['device_id']
            
            # OPTIMIZED QUERY: Use same optimized query as PHP for consistency
            # This query is optimized to use indexes on (device_id, date) and filter conditions
            cursor.execute("""
                SELECT DISTINCT date as d
                FROM csv_data1
                WHERE device_id = %s
                AND (
                    (actual_min > 0 AND actual_max > 0 AND actual_avg > 0)
                    OR actual_value = 1
                )
                ORDER BY date DESC
            """, (device_id,))
            
            results = cursor.fetchall()
            dates = [str(row['d']) for row in results]
            
            # Cache dates to Redis
            cache_key = f"dates:{vehicles_id}"
            try:
                json_data = json.dumps(dates)
                redis_client.setex(cache_key, CACHE_EXPIRY_SECONDS, json_data)
                cached_count += 1
            except Exception as e:
                print(f"   ⚠️  Failed to cache dates for vehicle {vehicles_id}: {e}")
        
        print(f"✅ Pre-cached dates for {cached_count} vehicles")
        
    except Exception as e:
        print(f"⚠️  Error pre-caching dates: {e}")


def main():
    """
    Main function to generate JSON and cache to Redis.
    """
    print("=" * 60)
    print("JSON Generator & Redis Cache Builder")
    print("=" * 60)
    
    # Get parameters (device_id and date) from command line or use defaults
    device_id = sys.argv[1] if len(sys.argv) > 1 else "6361819"
    requested_date = sys.argv[2] if len(sys.argv) > 2 else datetime.now().strftime("%Y-%m-%d")
    
    print(f"📊 Generating data for device_id: {device_id}, date: {requested_date}")
    
    # Connect to Redis
    redis_client = connect_redis()
    
    # Connect to database
    conn, cursor = connect_database()
    if conn is None or cursor is None:
        print("❌ Cannot proceed without database connection")
        sys.exit(1)
    
    try:
        # Pre-cache dates for all vehicles (improves get-dates-by-vehicles-id API)
        if redis_client:
            cache_dates_for_all_vehicles(redis_client, cursor)
        
        # Generate chart data from database
        print("🔄 Generating chart data from database...")
        data = generate_chart_data(device_id, requested_date, cursor)
        
        # Save to JSON file (fallback storage)
        print("💾 Saving to JSON file...")
        save_json_file(data, device_id, requested_date)
        
        # Cache to Redis
        if redis_client:
            print("🚀 Caching to Redis...")
            cache_to_redis(redis_client, data, device_id, requested_date)
        
        print("✅ Process completed successfully!")
        
    except Exception as e:
        print(f"❌ Error in main process: {e}")
        import traceback
        traceback.print_exc()
        sys.exit(1)
    
    finally:
        # Cleanup
        if cursor:
            cursor.close()
        if conn:
            conn.close()
        if redis_client:
            redis_client.close()


if __name__ == "__main__":
    main()

