import polars as pl
from datetime import datetime, timedelta
import operator
from typing import Any, Dict, List, Tuple
import mysql.connector

_OPS = {
    "+": operator.add,
    "-": operator.sub,
    "*": operator.mul,
    "/": operator.truediv,
}

conn = mysql.connector.connect(

        host="no-reply.com.au",

        username="noreplycom_smartdatalinkuser",

        password="Z2jq;6;Dm2E@",

        database="noreplycom_smartdatalink",

    )

cursor = conn.cursor()



cursor.execute("SELECT * FROM manual_readings")

mapping_data = cursor.fetchall()



class DataProcessor:

    def __init__(self):

        pass



    def process_analog(self, file_path: str) -> dict:

        """

        Processes an analog CSV file and returns a dictionary with device info and analog readings.

        """
        self.dynamic_code_mapping: Dict[str, Dict[str, Any]] = {}

        for row in mapping_data:
            if row[3] != "Analogue":
                continue

            code = f"A{int(row[2])}"

            # default OFF = 0.0 if NULL in DB
            OFF = float(row[7]) if row[7] is not None else 0.0

            raw_op = row[11] or "*"
            op_str = raw_op if raw_op in _OPS else "*"

            # default MF = 1.0 if NULL in DB
            MF = float(row[12]) if row[12] is not None else 1.0

            self.dynamic_code_mapping[code] = {
                "id":                   row[0],
                "name":                 row[1],
                "offset":               OFF,
                "calc_operator":        op_str,
                "multiplication_factor": MF,
            }

        # 1) Load the CSV
        df = pl.read_csv(
            file_path,
            has_header=False,
            truncate_ragged_lines=True
        )

        # 2) Pull device, date, time from first row
        first = df.row(0)
        device_id = first[2]
        date = datetime.strptime(str(first[5]), "%Y%m%d").date()
        ts = str(first[6]).zfill(6)
        base_time = datetime.strptime(ts, "%H%M%S").time()

        # 3) Figure out which column is the Axxx code
        code_col = df.columns[0]  # typically "column_0"

        # 4) Strip off 3-row header + 3-row footer, then filter
        body = df.slice(3, df.height - 3)
        body = body.filter(pl.col(code_col) != "END")
        body = body.filter(pl.col(code_col).is_in(list(self.dynamic_code_mapping.keys())))

        # 5) Compute each reading
        readings: List[Dict[str, Any]] = []
        for row in body.iter_rows():
            code      = row[0]
            orig_min  = float(row[4])
            orig_max  = float(row[6])
            orig_avg  = float(row[8])
            value     = float(row[2])

            m         = self.dynamic_code_mapping[code]
            op_fn     = _OPS[m["calc_operator"]]
            MF, OFF   = m["multiplication_factor"], m["offset"]

            inner     = op_fn(MF, OFF)

            min_value = orig_min * inner
            max_value = orig_max * inner
            avg_value = orig_avg * inner
            
            if min_value == 0:
                min_value = orig_min
            if max_value == 0:
                max_value = orig_max
            if avg_value == 0:
                avg_value = orig_avg

            readings.append({
                "Device ID":          device_id,
                "Date":               str(date),
                "Time":               str(base_time),
                "name":               m["name"],
                "id":                 m["id"],
                "number":             int(code[1:]),
                "type":               "Analogue",
                "min":                min_value,
                "max":                max_value,
                "avg":                avg_value,
                "original_min_value": orig_min,
                "original_max_value": orig_max,
                "original_avg_value": orig_avg,
                "value":              value,
            })

        return readings







    def process_digital(self, file_path: str) -> dict:

        """

        Processes a digital CSV file and returns a dictionary with device info and digital readings.

        """

        df = pl.read_csv(

            file_path,

            has_header=False,

            truncate_ragged_lines=True

        )

        



        dynamic_code_mapping = {

            f"D{row[2]}": {

                "id": row[0],

                "name": row[1]

            }

            for row in mapping_data

            if row[3] == "Digital"

            }



        inverse_flags = {

            f"D{row[2]}": bool(row[8])

            for row in mapping_data

            if row[3] == "Digital"

        }

        





        first_row = df.row(0)

        device_id = first_row[2]

        date_str = first_row[5]

        time_str = first_row[6]

        time_str=str(time_str)



        date = datetime.strptime(str(date_str), '%Y%m%d').date()

        if len(time_str) == 2:  

            time_str = '0000'+time_str  

        elif len(time_str) == 1:

            time_str = '00000' + time_str

        elif len(time_str) == 3:

            time_str = '0'+ time_str

        



        base_time = datetime.strptime(time_str, "%H%M%S")



        manual_readings = []

        df = df.slice(4, df.height - 4)

        df = df.filter(pl.col("column_1") != "END")

        df_filtered = df.filter(pl.col("column_1").is_in(list(dynamic_code_mapping.keys())))



        def invert_value(value, inverse_flag):

            s_val = str(value)

            if inverse_flag:

                if s_val == "0":

                    return "1"

                elif s_val == "1":

                    return "0"

            return s_val



        for row in df_filtered.iter_rows():

            code = row[0]

            mapping_info = dynamic_code_mapping.get(code, {"name": "Unknown", "id": None})

            inverse_flag = inverse_flags.get(code, False)

            

            for i in range(1, len(row), 2):

                t_val = row[i]

                if isinstance(t_val, str) and t_val.startswith("T"):

                    offset = int(t_val[1:]) if len(t_val) > 1 else 0

                    reading_time = (base_time + timedelta(minutes=offset)).strftime("%H%M%S")

                    

                    if len(reading_time) == 2:  

                        reading_time = '0000'+reading_time  

                    elif len(reading_time) == 1:

                        reading_time = '00000' + reading_time

                    elif len(reading_time) == 3:

                        reading_time = '0'+ reading_time



                    reading_time = datetime.strptime(reading_time, "%H%M%S").time()

               

                    if i + 1 < len(row):

                        original_value = row[i + 1]

                    else:

                        original_value = ""

                    value = invert_value(original_value, inverse_flag)

                    

                    manual_readings.append({

                        "id": mapping_info["id"],

                        "Device ID": device_id,

                        "Date": str(date),

                        "Time": str(reading_time),

                        "name": mapping_info["name"],

                        "number": int(code[1:]),

                        "type": "Digital",

                        "value": int(value),

                        "original_value":int(original_value)

                    })



        return manual_readings