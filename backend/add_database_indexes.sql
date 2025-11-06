-- Database Indexes for Performance Optimization
-- Run this script on your database to improve API response times

-- ============================================================================
-- Index for get-dates-by-vehicles-id API
-- ============================================================================

-- Primary index for device_id and date (most important for dates query)
CREATE INDEX IF NOT EXISTS idx_csv_data1_device_date 
ON csv_data1(device_id, date);

-- Composite index for the query conditions
CREATE INDEX IF NOT EXISTS idx_csv_data1_conditions 
ON csv_data1(device_id, actual_min, actual_max, actual_avg, actual_value);

-- Index on date column for faster sorting
CREATE INDEX IF NOT EXISTS idx_csv_data1_date 
ON csv_data1(date);

-- ============================================================================
-- Index for get-charts-data-1 API
-- ============================================================================

-- Index for device_id, date, and time (for chart data queries)
CREATE INDEX IF NOT EXISTS idx_csv_data1_device_date_time 
ON csv_data1(device_id, date, time);

-- Index for manual_readings_id lookups
CREATE INDEX IF NOT EXISTS idx_csv_data1_manual_readings 
ON csv_data1(manual_readings_id);

-- ============================================================================
-- Index for devices table
-- ============================================================================

-- Index for vehicles_id lookups
CREATE INDEX IF NOT EXISTS idx_devices_vehicles_id 
ON devices(vehicles_id);

-- ============================================================================
-- Verify indexes were created
-- ============================================================================

-- Run this to check indexes:
-- SHOW INDEX FROM csv_data1;
-- SHOW INDEX FROM devices;

