import React, { useState, useEffect } from 'react';
import styles from './AssetSelectionModal.module.css';

interface Vehicle {
  id: number;
  name: string;
  rego?: string;
}

interface AssetSelectionModalProps {
  onShowGraph: (vehicleId: number, date: string) => void;
}

const AssetSelectionModal: React.FC<AssetSelectionModalProps> = ({ onShowGraph }) => {
  const [vehicles, setVehicles] = useState<Vehicle[]>([]);
  const [dates, setDates] = useState<string[]>([]);
  const [selectedVehicleId, setSelectedVehicleId] = useState<number | null>(null);
  const [selectedDate, setSelectedDate] = useState<string>('');
  const [loadingVehicles, setLoadingVehicles] = useState<boolean>(true);
  const [loadingDates, setLoadingDates] = useState<boolean>(false);
  const [error, setError] = useState<string>('');

  // Fetch vehicles on mount
  useEffect(() => {
    const fetchVehicles = async () => {
      try {
        setLoadingVehicles(true);
        setError(''); // Clear previous errors
        
        // Vehicles endpoint (use reet_python API via proxy)
        const apiUrl = '/reet_python/get_vehicles.php';
        console.log('🔗 Fetching vehicles from:', apiUrl);
        
        const response = await fetch(apiUrl, {
          headers: { 'Accept': 'application/json' },
          cache: 'no-store',
          mode: 'cors'
        });
        
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const json = await response.json();
        console.log('✅ Vehicles API response:', json);
        
        // Map reet_python response: [{ devices_serial_no: "6363299" }, ...]
        const vehiclesData: Vehicle[] = (Array.isArray(json) ? json : [])
          .map((v: any) => String(v?.devices_serial_no || ''))
          .filter((s: string) => s.length > 0)
          .map((serial: string) => ({ id: Number(serial), name: serial, rego: serial }));
        
        console.log('📋 Processed vehicles:', vehiclesData);
        
        if (vehiclesData.length === 0) {
          throw new Error('No vehicles found in API response');
        }
        
        setVehicles(vehiclesData);
        if (vehiclesData.length > 0) {
          setSelectedVehicleId(vehiclesData[0].id);
        }
      } catch (err: any) {
        const errorMsg = err.message || 'Failed to load vehicles. Please check the API endpoint.';
        setError(errorMsg);
        console.error('❌ Error fetching vehicles:', err);
        console.error('Error details:', {
          message: err.message,
          stack: err.stack
        });
        setVehicles([]);
      } finally {
        setLoadingVehicles(false);
      }
    };
    fetchVehicles();
  }, []);

  // Fetch dates when vehicle is selected
  useEffect(() => {
    if (selectedVehicleId) {
      const fetchDates = async () => {
        try {
          setLoadingDates(true);
          setSelectedDate(''); // Reset date when vehicle changes
          setError(''); // Clear previous errors
          
          // Use the reet_python endpoint with devices_serial_no parameter (via proxy)
          const apiUrl = `/reet_python/get_vehicle_dates.php?devices_serial_no=${selectedVehicleId}`;
          console.log('🔗 Fetching dates from:', apiUrl);
          
          const response = await fetch(apiUrl, {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
            mode: 'cors'
          });
          
          if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
          }
          
          const json = await response.json();
          console.log('✅ Dates API response:', json);
          
          // Map reet_python response: [{ date: "YYYY-MM-DD" }, ...]
          let datesData: string[] = (Array.isArray(json) ? json : [])
            .map((o: any) => String(o?.date || ''))
            .filter((d: string) => d.length > 0);
          
          // Ensure dates are strings and sort them (newest first)
          datesData = datesData
            .map((d: any) => String(d))
            .filter((d: string) => d.length > 0)
            .sort((a: string, b: string) => b.localeCompare(a)); // Sort descending (newest first)
          
          console.log('📅 Processed dates:', datesData);
          
          // Don't show error if no dates found, just set empty array
          setDates(datesData);
          setError(''); // Clear any previous errors on successful fetch
        } catch (err: any) {
          // Only show error for actual API failures, not for empty responses
          const errorMsg = err.message || 'Failed to load dates. Please check the API endpoint.';
          // Don't show error for "No dates found" - just log it
          if (!errorMsg.includes('No dates found')) {
            setError(errorMsg);
          } else {
            setError('');
          }
          console.error('❌ Error fetching dates:', err);
          console.error('Error details:', {
            message: err.message,
            stack: err.stack,
            vehicleId: selectedVehicleId
          });
          setDates([]);
        } finally {
          setLoadingDates(false);
        }
      };
      fetchDates();
    } else {
      setDates([]);
      setSelectedDate('');
    }
  }, [selectedVehicleId]);

  const handleShowGraph = () => {
    if (selectedVehicleId && selectedDate) {
      onShowGraph(selectedVehicleId, selectedDate);
    }
  };

  const isFormValid = selectedVehicleId !== null && selectedDate !== '';

  return (
    <div className={styles.modalOverlay}>
      <div className={styles.modalContent}>
        <h2 className={styles.modalTitle}>Asset Chart</h2>
        <p className={styles.modalInstruction}>
          Kindly select the asset and date you'd like to proceed with.
        </p>

        {error && (
          <div className={styles.errorMessage}>{error}</div>
        )}

        <div className={styles.formGroup}>
          <label className={styles.label}>Select Asset</label>
          <select
            className={styles.select}
            value={selectedVehicleId || ''}
            onChange={(e) => {
              setSelectedVehicleId(Number(e.target.value));
              setError(''); // Clear error when vehicle changes
            }}
            disabled={loadingVehicles}
          >
            <option value="">Select Asset</option>
            {vehicles.map((vehicle) => (
              <option key={vehicle.id} value={vehicle.id}>
                {vehicle.rego || vehicle.name}
              </option>
            ))}
          </select>
        </div>

        <div className={styles.formGroup}>
          <label className={styles.label}>Select Date</label>
          <select
            className={styles.select}
            value={selectedDate}
            onChange={(e) => setSelectedDate(e.target.value)}
            disabled={loadingDates || !selectedVehicleId}
          >
            <option value="">Select Date</option>
            {dates.map((date) => (
              <option key={date} value={date}>
                {date}
              </option>
            ))}
          </select>
          {loadingDates && selectedVehicleId && (
            <div className={styles.loadingText}>Loading dates...</div>
          )}
        </div>

        <button
          className={styles.showButton}
          onClick={handleShowGraph}
          disabled={!isFormValid || loadingVehicles || loadingDates}
        >
          Show Graph
        </button>
      </div>
    </div>
  );
};

export default AssetSelectionModal;

