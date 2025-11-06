import React, { useEffect, useMemo, useState } from 'react';
import styles from './FilterControls.module.css';

const FilterControls: React.FC = () => {
  type Vehicle = { id: string; rego: string };
  const [vehicles, setVehicles] = useState<Vehicle[]>([]);
  const [selectedVehicleId, setSelectedVehicleId] = useState<string>('');
  const [dates, setDates] = useState<string[]>([]);
  const [selectedDate, setSelectedDate] = useState<string>('');

  // Don't initialize from URL params - user must select from AssetSelectionModal

  // Load vehicles from API with local fallback
  useEffect(() => {
    let aborted = false;
    const load = async () => {
      try {
        // Fetch from reet_python vehicles API (via proxy)
        const apiRes = await fetch('/reet_python/get_vehicles.php', { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
        if (!apiRes.ok) throw new Error('bad');
        const json = await apiRes.json();
        // Map response: [{ devices_serial_no: "6363299" }, ...]
        const arr: Vehicle[] = Array.isArray(json)
          ? json.map((v: any) => String(v?.devices_serial_no || ''))
              .filter((s: string) => s.length > 0)
              .map((serial: string) => ({ id: serial, rego: serial }))
          : [];
        if (aborted) return;
        setVehicles(arr);
        // Don't auto-select - user must select from AssetSelectionModal first
      } catch {
        // Minimal hardcoded fallback if API fails
        const fallback: Vehicle[] = [];
        if (!aborted) {
          setVehicles(fallback);
        }
      }
    };
    load();
    return () => { aborted = true; };
  }, [selectedVehicleId]);

  // Fetch dates when vehicle changes
  useEffect(() => {
    let aborted = false;
    const loadDates = async () => {
      if (!selectedVehicleId) { setDates([]); setSelectedDate(''); return; }
      try {
        // Fetch from reet_python dates API using devices_serial_no (via proxy)
        const url = `/reet_python/get_vehicle_dates.php?devices_serial_no=${selectedVehicleId}`;
        const res = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
        if (!res.ok) throw new Error('bad');
        const json = await res.json();
        // Map response: [{ date: "YYYY-MM-DD" }, ...]
        let arr: string[] = Array.isArray(json) ? json.map((o: any) => String(o?.date || '')) : [];
        arr = arr.filter((d: string) => d.length > 0);
        arr.sort((a, b) => b.localeCompare(a));
        if (aborted) return;
        setDates(arr);
        // Don't auto-select date - user must select from AssetSelectionModal first
      } catch (e) {
        if (!aborted) { setDates([]); setSelectedDate(''); }
      }
    };
    loadDates();
    return () => { aborted = true; };
  }, [selectedVehicleId]);

  // Don't automatically dispatch filters:apply - only dispatch when user explicitly applies filters
  // The AssetSelectionModal will handle the initial selection via handleShowGraph

  return (
    <div className={styles.filterControls}>
      <div className={styles.container}>
        <div className={styles.leftControls}>
          <h1 className={styles.title}>Charts</h1>
          
          <select 
            className={styles.select}
            value={selectedVehicleId}
            onChange={(e) => setSelectedVehicleId(e.target.value)}
          >
            {vehicles.map(v => (
              <option key={v.id} value={v.id}>{v.rego}</option>
            ))}
          </select>
          
          <select 
            className={styles.select}
            value={selectedDate}
            onChange={(e) => setSelectedDate(e.target.value)}
            disabled={!selectedVehicleId}
          >
            <option value="">Select Date</option>
            {dates.map(d => (
              <option key={d} value={d}>{d}</option>
            ))}
          </select>

          <button className={styles.filterButton} onClick={() => window.dispatchEvent(new CustomEvent('filters:open'))}>Additional Filters</button>
        </div>
        
      
        
        <div className={styles.rightControls}>
          <div className={styles.actionButtons}>
            <button className={styles.actionBtn}>Table</button>
            <button className={styles.actionBtn} onClick={() => window.print()}>Print</button>
          </div>
          
        
        </div>
      </div>
    </div>
  );
};

export default FilterControls;
