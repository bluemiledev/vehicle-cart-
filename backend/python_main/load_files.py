import schedule
import time
import ftplib
import io
from functions import DataProcessor
import mysql.connector
from datetime import datetime


def process_ftp_data():
    """
    Function to process FTP data and update database.
    Runs every 5 minutes and clears temp_data before each execution.
    """
    conn = mysql.connector.connect(

            host="no-reply.com.au",

            username="noreplycom_smartdatalinkuser",

            password="Z2jq;6;Dm2E@",

            database="noreplycom_smartdatalink",

        )

    cursor = conn.cursor()



    FTP_HOST = "no-reply.com.au"

    FTP_USER = "samar@no-reply.com.au"

    FTP_PASS = "w-_iyVspLbFU"
    BASE_DIR = "/"



    def is_directory(ftp: ftplib.FTP, name: str) -> bool:
        """
        Return True if `name` is a directory on the FTP server.
        """
        # 1) Try MLSD if available
        try:
            for entry_name, entry_meta in ftp.mlsd():
                if entry_name == name:
                    return entry_meta.get('type') == 'dir'
        except (AttributeError, ftplib.error_perm):
            # MLSD unsupported or permission denied → fallback
            pass

        # 2) Fallback: try cwd
        try:
            current = ftp.pwd()
            ftp.cwd(name)
            ftp.cwd(current)
            return True
        except ftplib.error_perm:
            # “550 Not a directory” or permission denied
            return False
        except EOFError:
            # server closed data connection unexpectedly
            return False
        except Exception:
            # any other error → assume not a dir
            return False

    def ensure_directory(ftp: ftplib.FTP, dirname: str):
        """
        Ensure that a directory exists on the FTP server.
        If not, create it.
        """
        try:
            ftp.mkd(dirname)
            print(f"Created directory: {dirname}")
        except ftplib.error_perm:
            pass  # probably already exists

    # --- CONNECT & NAVIGATE ---
    ftp = ftplib.FTP()
    ftp.connect(FTP_HOST, timeout=3000)
    ftp.login(FTP_USER, FTP_PASS)
    ftp.set_pasv(True)  # most servers require passive mode

    print("Connected to FTP Server")
    ftp.cwd(BASE_DIR)
    print("Parent Directory:", ftp.pwd())

    # Prepare target directories
    ensure_directory(ftp, "completed_files")
    ftp.cwd("completed_files")
    ensure_directory(ftp, "csv_files")
    ftp.cwd(BASE_DIR)

    chart_files_dir   = f"{BASE_DIR}/chart_files/csv_files"
    completed_files_dir = f"{BASE_DIR}/completed_files/csv_files"

    ftp.cwd(chart_files_dir)

    # --- FIND CSVs ---
    try:
        # MLSD listing for file type if supported
        raw_entries = ftp.mlsd()
        files = [
            name for name, meta in raw_entries
            if meta.get('type') == 'file' and name.lower().endswith('.csv')
        ]
    except (ftplib.error_perm, AttributeError):
        # Fallback to NLST + is_directory
        names = ftp.nlst()
        files = [
            name for name in names
            if not is_directory(ftp, name) and name.lower().endswith('.csv')
        ]

    print("CSV files to process:", files)
    
    



    processor = DataProcessor()

    cursor.execute("""

    CREATE TABLE IF NOT EXISTS temp_data (

        id INT AUTO_INCREMENT PRIMARY KEY,

        chartName VARCHAR(255),

        manual_readings_id INT,

        timings_id INT,

        time TIME,

        chartType VARCHAR(100),

        min INT,

        max INT,

        avg INT,

        value INT,

        date DATE,

        device_id VARCHAR(100),

        response_data INT,

        actual_min INT,

        actual_max INT,

        actual_avg INT,

        actual_value INT,
        
        INDEX idx_join (date, device_id, time, manual_readings_id)
        

    );

    """)

    conn.commit()

    # Create indexes on main table if they don't exist
    try:
        cursor.execute("""
        ALTER TABLE csv_data1 
        ADD INDEX idx_join (date, device_id, time, manual_readings_id)
        """)
        conn.commit()
    except mysql.connector.Error as err:
        print(f"Indexes might already exist: {err}")

    insert_query = """

    INSERT INTO temp_data (

        chartName, 

        manual_readings_id,

        timings_id, 

        time,

        chartType, 

        min, 

        max, 

        avg, 

        value,  

        date, 

        device_id,

        response_data, 

        actual_min,

        actual_max,

        actual_avg,

        actual_value

    ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)"""





    files_to_move = []



    for filename in files:

        print(f"Processing file: {filename}")
        bio = io.BytesIO()
        ftp.retrbinary(f"RETR {filename}", bio.write)
        bio.seek(0)



        if filename.endswith("_200.csv"):

            digital_output = processor.process_digital(bio)

            

            for record in digital_output:

                t_str = record['Time']

                t = datetime.strptime(t_str, "%H:%M:%S").strftime("%H:%M:00")

                values = (

                    record['name'],

                    record['id'],    

                    record['number'],

                    t,     

                    record['type'],         

                    0,                     

                    0,                      

                    0,                       

                    record['value'],                            

                    record['Date'],         

                    record['Device ID'], 

                    0,     

                    0,

                    0,

                    0,

                    record['original_value']

                )

                cursor.execute(insert_query, values)

                conn.commit()

                print("Rows updated:", cursor.rowcount)

                

                print("Inserted digital record:", values)

        elif filename.endswith("_300.csv"):

            analog_output = processor.process_analog(bio)

            for record in analog_output:

                t_str = record['Time']

                t = datetime.strptime(t_str, "%H:%M:%S").strftime("%H:%M:00")

                values = (

                    record['name'], 

                    record['id'],

                    record['number'],

                    t,

                    record['type'],              # chartType

                    record.get('min'),        # min

                    record.get('max'),        # max

                    record.get('avg'),

                    record['value'],

                    record['Date'],              # WHERE clause: date

                    record['Device ID'],

                    0,

                    record["original_min_value"],

                    record["original_max_value"],

                    record["original_avg_value"],

                    record['value']

                )

                cursor.execute(insert_query, values)

                conn.commit()

                print("Rows updated:", cursor.rowcount)



                print("Inserted analog record:", values)

        else:

            print("Skipping file (pattern not matched):", filename)
            continue
        
        files_to_move.append(filename)


        

    update_query = """

    UPDATE csv_data1 

    JOIN temp_data ON 

        csv_data1.date = temp_data.date 

        AND csv_data1.device_id = temp_data.device_id 

        AND csv_data1.time = temp_data.time 

        AND csv_data1.manual_readings_id = temp_data.manual_readings_id

    SET 

        csv_data1.chartName = temp_data.chartName,

        csv_data1.chartType = temp_data.chartType,

        csv_data1.min = temp_data.min,

        csv_data1.max = temp_data.max,

        csv_data1.avg = temp_data.avg,

        csv_data1.value = temp_data.value,

        csv_data1.response_data = temp_data.response_data,

        csv_data1.date = temp_data.date,

        csv_data1.device_id = temp_data.device_id,

        csv_data1.time = temp_data.time,

        csv_data1.manual_readings_id = temp_data.manual_readings_id,

        csv_data1.actual_min = temp_data.actual_min,

        csv_data1.actual_max = temp_data.actual_max,

        csv_data1.actual_avg = temp_data.actual_avg,

        csv_data1.actual_value = temp_data.actual_value;

    """

    cursor.execute(update_query)

    conn.commit()
    
    cursor.execute("TRUNCATE TABLE temp_data")


    

    
    
    # Move processed files to destination

    for filename in files_to_move:
        source = f"{chart_files_dir}/{filename}"
        destination = f"{completed_files_dir}/{filename}"
        try:
            ftp.rename(source, destination)
            print(f"Moved {source} to {destination}")
        except ftplib.all_errors as e:
            print(f"Failed to move {source}: {e}")

    

        



    ftp.quit()
    cursor.close()
    conn.close()
    
    # ============================================================================
    # REDIS CACHING: After processing CSV and updating database,
    # generate JSON and cache it to Redis for fast API responses
    # ============================================================================
    try:
        print("\n" + "="*60)
        print("Generating JSON cache for Redis...")
        print("="*60)
        
        # Import the JSON generator script
        import subprocess
        import sys
        
        # Get the path to generate_json_cache.py
        script_dir = os.path.dirname(os.path.abspath(__file__))
        json_cache_script = os.path.join(script_dir, 'generate_json_cache.py')
        
        # Run the JSON generator (this will cache to Redis)
        # Note: You may want to generate for multiple devices/dates
        # For now, we'll generate for the most recent device/date
        result = subprocess.run(
            [sys.executable, json_cache_script],
            capture_output=True,
            text=True
        )
        
        if result.returncode == 0:
            print("✅ JSON cache generation completed")
            if result.stdout:
                print(result.stdout)
        else:
            print(f"⚠️  JSON cache generation had issues: {result.stderr}")
    except Exception as e:
        print(f"⚠️  Failed to generate JSON cache: {e}")
        print("   Data processing completed, but Redis cache not updated")
        # Don't fail the entire process if Redis caching fails

    
process_ftp_data()
# Schedule the function to run every 5 minutes
# schedule.every(2).minutes.do(process_ftp_data)

# print("Scheduler started. Running every 1 minutes...")

# while True:
#     schedule.run_pending()
#     time.sleep(1)

