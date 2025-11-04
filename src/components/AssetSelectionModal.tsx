import React, { useState, useEffect } from 'react';
import styles from './AssetSelectionModal.module.css';

interface Vehicle {
  id: number;
  name: string;
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
        const response = await fetch('https://www.smartdatalink.com.au/get-vehicles', {
          headers: { 'Accept': 'application/json' },
          cache: 'no-store'
        });
        if (!response.ok) throw new Error('Failed to fetch vehicles');
        const json = await response.json();
        const vehiclesData = json.data || [];
        setVehicles(vehiclesData);
        if (vehiclesData.length > 0) {
          setSelectedVehicleId(vehiclesData[0].id);
        }
      } catch (err: any) {
        setError('Failed to load vehicles. Please try again.');
        console.error('Error fetching vehicles:', err);
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
          const response = await fetch(`https://www.smartdatalink.com.au/get-dates-by-devices-id?id=${selectedVehicleId}`, {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
          });
          if (!response.ok) throw new Error('Failed to fetch dates');
          const json = await response.json();
          // Handle different possible response formats
          let datesData: string[] = [];
          if (Array.isArray(json.data)) {
            datesData = json.data;
          } else if (Array.isArray(json)) {
            datesData = json;
          } else if (json.dates && Array.isArray(json.dates)) {
            datesData = json.dates;
          }
          // Ensure dates are strings and sort them (newest first)
          datesData = datesData
            .map((d: any) => String(d))
            .filter((d: string) => d.length > 0)
            .sort((a: string, b: string) => b.localeCompare(a)); // Sort descending (newest first)
          setDates(datesData);
        } catch (err: any) {
          setError('Failed to load dates. Please try again.');
          console.error('Error fetching dates:', err);
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
            onChange={(e) => setSelectedVehicleId(Number(e.target.value))}
            disabled={loadingVehicles}
          >
            <option value="">Select Asset</option>
            {vehicles.map((vehicle) => (
              <option key={vehicle.id} value={vehicle.id}>
                {vehicle.name}
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

