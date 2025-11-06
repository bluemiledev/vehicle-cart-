# test_script.py
import sys
import json
import mysql.connector

 # Connect to the database
conn = mysql.connector.connect(
    host="127.0.0.1",
    user="noreplycom_smartdatalinkuser",
    password="Z2jq;6;Dm2E@",
    database="noreplycom_smartdatalink"
)
cursor = conn.cursor()


data = {
    "status": "success",
    "message": "Python script executed successfully!",
    "arguments": sys.argv[1:]
    
}

print(json.dumps(data))
