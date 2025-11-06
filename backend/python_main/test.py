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



    def is_directory(ftp, name):

        """

        Check if an item on the FTP server is a directory.

        """

        current = ftp.pwd()

        try:

            ftp.cwd(name)

            ftp.cwd(current) 

            return True

        except ftplib.error_perm:

            return False



    def ensure_directory(ftp, dirname):

        """

        Ensure that a directory exists on the FTP server.

        If not, create it.

        """

        try:

            ftp.mkd(dirname)

            print(f"Created directory: {dirname}")

        except ftplib.error_perm:

            # Directory might already exist, so we can ignore the error.

            pass





    ftp = ftplib.FTP()

    ftp.connect(FTP_HOST, timeout=3000)

    ftp.login(FTP_USER, FTP_PASS)

    print("Connected to FTP Server")

    parent_dir = ftp.pwd()

    print("Parent Directory:", parent_dir)





    chart_files_dir = f"{parent_dir}/chart_files"

    completed_files_dir = f"{parent_dir}/completed_files"



    ftp.cwd(parent_dir)

    ensure_directory(ftp, "completed_files")





    ftp.set_pasv(False)



    ftp.cwd(chart_files_dir)



    subdirs = [sd for sd in ftp.nlst() if sd not in ('.', '..') and is_directory(ftp, sd)]

    print("Subdirectories to process:", subdirs)



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





    # Iterate over each subdirectory using a for loop

    for subdir in subdirs:

        subdir_path = f"{chart_files_dir}/{subdir}"

        try:

            ftp.cwd(subdir_path)

        except ftplib.error_perm:

            print(f"Directory '{subdir_path}' no longer exists, skipping.")

            continue



        print(f"\nProcessing subdirectory: {subdir}")

        files = ftp.nlst()



        for filename in files:

            if filename.lower().endswith('.csv'):

                print(f"Reading file: {filename} in subdirectory: {subdir}")

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

        



        

        update_query = """

        UPDATE csv_data1 

        JOIN temp_data ON 

            csv_data1.date = temp_data.date 

            AND csv_data1.device_id = temp_data.device_id 

            AND csv_data1.time = temp_data.time 

            AND csv_data1.manual_readings_id = temp_data.manual_readings_id

        SET 

            csv_data1.chartName = temp_data.chartName,

            csv_data1.timings_id = temp_data.timings_id,

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


        ftp.cwd(chart_files_dir)

        

        source_path = subdir_path

        destination_path = f"{completed_files_dir}/{subdir}"

        try:

            ftp.rename(source_path, destination_path)

            print(f"Moved subdirectory '{source_path}' to '{destination_path}'")

        except ftplib.all_errors as e:

            print(f"Error moving directory '{source_path}' to '{destination_path}': {e}")

        

        



    ftp.quit()
    cursor.close()
    conn.close()

    

# Schedule the function to run every 5 minutes
schedule.every(5).minutes.do(process_ftp_data)

print("Scheduler started. Running every 5 minutes...")

while True:
    schedule.run_pending()
    time.sleep(1)
