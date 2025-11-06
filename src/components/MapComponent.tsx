import React from 'react';
import { MapContainer, TileLayer, Marker, Popup, useMap, Polyline } from 'react-leaflet';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import styles from './MapComponent.module.css';
import { useEffect, useMemo, useState } from 'react';
import { useTimeContext } from '../context/TimeContext';

// Fix for default markers in react-leaflet
delete (L.Icon.Default.prototype as unknown as { _getIconUrl: unknown })._getIconUrl;
L.Icon.Default.mergeOptions({
  iconRetinaUrl: require('leaflet/dist/images/marker-icon-2x.png'),
  iconUrl: require('leaflet/dist/images/marker-icon.png'),
  shadowUrl: require('leaflet/dist/images/marker-shadow.png'),
});

// Custom vehicle marker icon
const vehicleIcon = new L.Icon({
  iconUrl: `data:image/svg+xml;base64,${btoa(`
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="12" cy="12" r="10" fill="#ff6b35" stroke="#ffffff" stroke-width="2"/>
      <path d="M8 12h8M12 8v8" stroke="#ffffff" stroke-width="2" stroke-linecap="round"/>
    </svg>
  `)}`,
  iconSize: [24, 24],
  iconAnchor: [12, 12],
  popupAnchor: [0, -12],
});

type GpsPoint = { time: number; lat: number; lng: number };

const MapComponent: React.FC = () => {
  // Australia (Brisbane area)
  const center: [number, number] = [-27.4698, 153.0251];
  const { selectedTime } = useTimeContext();
  const [gpsPoints, setGpsPoints] = useState<GpsPoint[]>([]);
  const [selectedVehicleId, setSelectedVehicleId] = useState<string | null>(null);
  const [selectedDate, setSelectedDate] = useState<string | null>(null);

  // Listen to filters:apply event to get vehicle and date selection
  useEffect(() => {
    const onApply = (e: any) => {
      console.log('🗺️ MapComponent received filters:apply event:', e?.detail);
      const deviceId = String(e?.detail?.device_id || '');
      const date = String(e?.detail?.date || '');
      console.log('🗺️ Extracted device_id:', deviceId, 'date:', date);
      if (deviceId && date) {
        console.log('🗺️ Setting GPS data loading for:', { deviceId, date });
        setSelectedVehicleId(deviceId);
        setSelectedDate(date);
      } else {
        console.warn('🗺️ Missing device_id or date in event:', { deviceId, date });
      }
    };
    window.addEventListener('filters:apply', onApply as any);
    console.log('🗺️ MapComponent listening for filters:apply events');
    return () => {
      window.removeEventListener('filters:apply', onApply as any);
    };
  }, []);

  useEffect(() => {
    if (!selectedVehicleId || !selectedDate) {
      setGpsPoints([]);
      return;
    }

    const loadGps = async () => {
      try {
        // Use the same endpoint as VehicleDashboard: create_json.php (via proxy)
        // Use relative URL so proxy can handle it
        const apiUrl = `/reet_python/create_json.php?reading_date=${encodeURIComponent(selectedDate)}&devices_serial_no=${encodeURIComponent(selectedVehicleId)}`;
        
        console.log('📍 Fetching GPS data from:', apiUrl);
        
        const apiRes = await fetch(apiUrl, { 
          headers: { 'Accept': 'application/json' }, 
          cache: 'no-store',
          mode: 'cors',
          credentials: 'omit'
        });
        if (!apiRes.ok) throw new Error('api failed');
        const json = await apiRes.json();
        // The create_json.php API returns data directly (not wrapped in {status, message, data})
        const payload: any = json;
        
        console.log('📍 Full API Response:', JSON.stringify(payload, null, 2));
        console.log('📍 gpsPerSecond from API:', payload?.gpsPerSecond);
        console.log('📍 gpsPerSecond type:', typeof payload?.gpsPerSecond);
        console.log('📍 gpsPerSecond isArray:', Array.isArray(payload?.gpsPerSecond));
        if (Array.isArray(payload?.gpsPerSecond) && payload.gpsPerSecond.length > 0) {
          console.log('📍 First gpsPerSecond point:', payload.gpsPerSecond[0]);
          console.log('📍 First 3 gpsPerSecond points:', payload.gpsPerSecond.slice(0, 3));
        }
        
        // Get timestamps array for date reference
        const timestamps = Array.isArray(payload?.timestamps) ? payload.timestamps : [];
        console.log('📍 Timestamps array:', timestamps);
        console.log('📍 Timestamps length:', timestamps.length);
        if (timestamps.length > 0) {
          console.log('📍 First timestamp:', timestamps[0]);
          console.log('📍 First timestamp type:', typeof timestamps[0]);
        }
        
        let baseDate: Date;
        if (timestamps.length > 0 && timestamps[0]) {
          const firstTimestamp = timestamps[0];
          // Handle different timestamp formats
          if (typeof firstTimestamp === 'string') {
            // String format: "2025-11-04T20:03:00" or "2025-11-04"
            baseDate = new Date(firstTimestamp.split('T')[0]);
          } else if (firstTimestamp.timestamp && typeof firstTimestamp.timestamp === 'string') {
            // Object format: { timestamp: "2025-11-04T20:03:00" }
            baseDate = new Date(firstTimestamp.timestamp.split('T')[0]);
          } else if (firstTimestamp.date && typeof firstTimestamp.date === 'string') {
            // Object format: { date: "2025-11-04" }
            baseDate = new Date(firstTimestamp.date);
          } else {
            // Fallback to selectedDate
            baseDate = new Date(selectedDate);
          }
        } else {
          baseDate = new Date(selectedDate);
        }
        baseDate.setHours(0, 0, 0, 0);
        console.log('📍 Base date for parsing:', baseDate.toISOString());
        
        const parseHMS = (hms: string) => {
          const [hh, mm, ss] = String(hms).split(':').map((n: string) => Number(n));
          return baseDate.getTime() + (hh || 0) * 3600000 + (mm || 0) * 60000 + (ss || 0) * 1000;
        };
        
        // Get GPS data from gpsPerSecond array
        // The API returns gpsPerSecond as an array of objects with lat and lng
        const arr: any[] = Array.isArray(payload?.gpsPerSecond)
          ? payload.gpsPerSecond
          : Array.isArray(payload?.gps_per_second)
          ? payload.gps_per_second
          : Array.isArray(payload?.gps)
          ? payload.gps
          : Array.isArray(payload?.locations)
          ? payload.locations
          : [];
        
        console.log('📍 GPS Data Structure:', {
          hasGpsPerSecond: !!payload?.gpsPerSecond,
          isArray: Array.isArray(payload?.gpsPerSecond),
          length: Array.isArray(payload?.gpsPerSecond) ? payload.gpsPerSecond.length : 'N/A',
          firstPoint: Array.isArray(payload?.gpsPerSecond) && payload.gpsPerSecond.length > 0 ? payload.gpsPerSecond[0] : null,
          payloadKeys: Object.keys(payload || {}),
          fullPayload: payload
        });
        
        const toNumber = (val: any): number => {
          if (typeof val === 'number') return val;
          if (typeof val === 'string') {
            const cleaned = val.replace(/[^0-9+\-\.]/g, '').replace('−', '-');
            const n = Number(cleaned);
            if (Number.isFinite(n)) return n;
          }
          return NaN;
        };
        
        const getLat = (p: any): number => {
          // Direct access to lat field first (most common)
          if (p.lat !== undefined && p.lat !== null) {
            const n = toNumber(p.lat);
            console.log(`📍 getLat: raw value = ${p.lat}, toNumber = ${n}, isFinite = ${Number.isFinite(n)}`);
            if (Number.isFinite(n)) {
              // If already in decimal degrees (between -90 and 90), use as-is
              if (Math.abs(n) <= 90) {
                console.log(`📍 getLat: Already in decimal degrees, returning ${n}`);
                return n;
              }
              // Handle DDDMM.MMMM format (e.g., 3754.56246 = 37°54.56246')
              // Check if it's in DDDMM.MMMM format (between 1000 and 10000)
              if (Math.abs(n) >= 1000 && Math.abs(n) < 10000) {
                // Extract degrees (first 2 digits) and minutes (rest including decimal)
                // Example: 3754.56246 → degrees = 37, minutes = 54.56246
                const absValue = Math.abs(n);
                const degrees = Math.floor(absValue / 100);
                const minutes = absValue % 100; // This correctly gets 54.56246 from 3754.56246
                let result = degrees + minutes / 60;
                // For Australia (southern hemisphere), latitude should be negative
                // If degrees > 20 (Australia is around 37°S), make it negative
                if (degrees > 20 && n > 0) {
                  result = -result;
                } else if (n < 0) {
                  result = -result;
                }
                console.log(`📍 Lat conversion: ${n} → ${degrees}°${minutes.toFixed(5)}' → ${result.toFixed(6)}°`);
                return result;
              } else {
                console.warn(`📍 getLat: Value ${n} doesn't match DDDMM.MMMM format (expected 1000-10000)`);
              }
            } else {
              console.warn(`📍 getLat: Value ${p.lat} is not a valid number`);
            }
          } else {
            console.warn(`📍 getLat: p.lat is undefined or null`);
          }
          // Fallback to other field names
          const v = p.Lat ?? p.LAT ?? p.latitude ?? p.Latitude ?? p.gpsLat ?? p.lat_val ?? p.latE7;
          if (v === undefined || v === null) return NaN;
          let n = toNumber(v);
          if (!Number.isFinite(n) && Array.isArray(p.coordinates)) n = toNumber(p.coordinates[1]);
          if (!Number.isFinite(n) && Array.isArray(p.coord)) n = toNumber(p.coord[1]);
          if (Number.isFinite(n) && Math.abs(n) <= 90) return n;
          if (Number.isFinite(n) && Math.abs(n) >= 1000 && Math.abs(n) < 10000) {
            const wholePart = Math.floor(Math.abs(n));
            const degrees = Math.floor(wholePart / 100);
            const minutes = wholePart % 100 + (Math.abs(n) - wholePart);
            let result = degrees + minutes / 60;
            if (degrees > 20 && n > 0) result = -result;
            else if (n < 0) result = -result;
            return result;
          }
          return n;
        };
        
        const getLng = (p: any): number => {
          // Direct access to lng field first (most common)
          if (p.lng !== undefined && p.lng !== null) {
            const n = toNumber(p.lng);
            if (Number.isFinite(n)) {
              // If already in decimal degrees (between -180 and 180), use as-is
              if (Math.abs(n) <= 180) return n;
              // Handle DDDMM.MMMM format (e.g., 14509.45524 = 145°09.45524')
              // Check if it's in DDDMM.MMMM format (between 10000 and 100000)
              if (Math.abs(n) >= 10000 && Math.abs(n) < 100000) {
                // Extract degrees (first 3 digits) and minutes (rest including decimal)
                // Example: 14509.45524 → degrees = 145, minutes = 9.45524
                const absValue = Math.abs(n);
                const degrees = Math.floor(absValue / 100);
                const minutes = absValue % 100; // This correctly gets 9.45524 from 14509.45524
                let result = degrees + minutes / 60;
                // Longitude: positive for eastern hemisphere (Australia), negative for western
                if (n < 0) {
                  result = -result;
                }
                console.log(`📍 Lng conversion: ${n} → ${degrees}°${minutes.toFixed(5)}' → ${result.toFixed(6)}°`);
                return result;
              }
            }
          }
          // Fallback to other field names
          const v = p.Lng ?? p.LNG ?? p.lon ?? p.long ?? p.Long ?? p.longitude ?? p.Longitude ?? p.gpsLng ?? p.lng_val ?? p.lngE7;
          if (v === undefined || v === null) return NaN;
          let n = toNumber(v);
          if (!Number.isFinite(n) && Array.isArray(p.coordinates)) n = toNumber(p.coordinates[0]);
          if (!Number.isFinite(n) && Array.isArray(p.coord)) n = toNumber(p.coord[0]);
          if (Number.isFinite(n) && Math.abs(n) <= 180) return n;
          if (Number.isFinite(n) && Math.abs(n) >= 10000 && Math.abs(n) < 100000) {
            const wholePart = Math.floor(Math.abs(n));
            const degrees = Math.floor(wholePart / 100);
            const minutes = wholePart % 100 + (Math.abs(n) - wholePart);
            let result = degrees + minutes / 60;
            if (n < 0) result = -result;
            return result;
          }
          return n;
        };
        
        console.log('📍 Processing GPS array, length:', arr.length);
        const pts: GpsPoint[] = arr.map((p: any, idx: number) => {
          const rawLat = p.lat;
          const rawLng = p.lng;
          const lat = getLat(p);
          const lng = getLng(p);
          
          // Debug all points (not just first 5)
          console.log(`📍 GPS point ${idx}:`, { 
            raw: { lat: rawLat, lng: rawLng },
            converted: { lat, lng },
            isValid: Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180,
            fullPoint: p
          });
          
          // If lat/lng are invalid, try to extract from nested structure
          let finalLat = lat;
          let finalLng = lng;
          
          if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
            console.warn(`📍 Point ${idx} has invalid coordinates, trying alternatives...`);
            // Try alternative structures
            if (p.coordinates && Array.isArray(p.coordinates)) {
              finalLng = toNumber(p.coordinates[0]);
              finalLat = toNumber(p.coordinates[1]);
              console.log(`📍 Using coordinates array:`, { lat: finalLat, lng: finalLng });
            } else if (p.position && typeof p.position === 'object') {
              finalLat = toNumber(p.position.lat ?? p.position.latitude);
              finalLng = toNumber(p.position.lng ?? p.position.longitude);
              console.log(`📍 Using position object:`, { lat: finalLat, lng: finalLng });
            }
          }
          
          return {
            time: (() => {
              // Try timestamp field first (ISO format: "2025-11-04T20:03:00")
              const ts = p.timestamp ?? p.timeStamp ?? p.ts ?? p.epoch ?? null;
              if (ts != null) {
                if (typeof ts === 'string') {
                  const parsed = Date.parse(ts);
                  if (Number.isFinite(parsed)) return parsed;
                } else {
                  const n = Number(ts);
                  if (Number.isFinite(n)) return n < 1e12 ? n * 1000 : n;
                }
              }
              // Fallback to time field (HH:mm:ss format)
              const timeStr = String(p.time ?? p.Time ?? p.TIME ?? p.hms ?? '00:00:00');
              const parsedTime = parseHMS(timeStr);
              return parsedTime;
            })(),
            lat: finalLat,
            lng: finalLng
          };
        });
        
        console.log('📍 Points before filtering:', pts.length);
        console.log('📍 Sample points before filter:', pts.slice(0, 3));
        
        const filteredPts = pts.filter(p => {
            const valid = Number.isFinite(p.lat) && Number.isFinite(p.lng) && 
                         Math.abs(p.lat) <= 90 && Math.abs(p.lng) <= 180;
            if (!valid) {
              console.warn('📍 Filtered invalid GPS point:', { lat: p.lat, lng: p.lng, time: p.time });
            }
            return valid;
          });
        
        console.log('📍 Points after filtering:', filteredPts.length);
        filteredPts.sort((a, b) => a.time - b.time);
        console.log('📍 GPS Points loaded:', filteredPts.length, 'points');
        console.log('📍 Total GPS data from API:', arr.length, 'points');
        if (filteredPts.length > 0) {
          console.log('📍 First GPS point:', { lat: filteredPts[0].lat, lng: filteredPts[0].lng, time: new Date(filteredPts[0].time).toISOString() });
          console.log('📍 Last GPS point:', { lat: filteredPts[filteredPts.length - 1].lat, lng: filteredPts[filteredPts.length - 1].lng, time: new Date(filteredPts[filteredPts.length - 1].time).toISOString() });
          console.log('📍 Sample GPS points (first 5):', filteredPts.slice(0, 5).map(p => ({ lat: p.lat, lng: p.lng })));
          console.log('📍 Map will center on:', [filteredPts[0].lat, filteredPts[0].lng]);
        } else if (arr.length > 0) {
          console.error('⚠️ GPS data received but all points were filtered out!');
          console.error('⚠️ Sample raw points:', arr.slice(0, 5));
          console.error('⚠️ Check if lat/lng values are valid numbers');
        } else {
          console.error('⚠️ No GPS data found in API response');
          console.error('⚠️ Payload keys:', Object.keys(payload || {}));
          console.error('⚠️ Full payload (first 1000 chars):', JSON.stringify(payload).substring(0, 1000));
        }
        setGpsPoints(filteredPts);
        
        // Force map update
        if (filteredPts.length > 0) {
          console.log('✅ GPS points set successfully, map should update');
        } else {
          console.error('❌ No valid GPS points to display');
        }
      } catch (e) {
        console.error('❌ Error loading GPS data:', e);
        setGpsPoints([]);
      }
    };
    loadGps();
  }, [selectedVehicleId, selectedDate]);

  const currentPosition = useMemo<[number, number] | null>(() => {
    if (gpsPoints.length === 0) return null;
    const t = selectedTime ? selectedTime.getTime() : Math.floor((gpsPoints[0].time + gpsPoints[gpsPoints.length - 1].time) / 2);
    let lo = 0, hi = gpsPoints.length - 1;
    while (lo < hi) {
      const mid = Math.floor((lo + hi) / 2);
      if (gpsPoints[mid].time < t) lo = mid + 1; else hi = mid;
    }
    const idx = lo;
    const prev = gpsPoints[Math.max(0, idx - 1)];
    const next = gpsPoints[Math.min(gpsPoints.length - 1, idx)];
    const pick = Math.abs((prev?.time ?? Infinity) - t) <= Math.abs((next?.time ?? Infinity) - t) ? prev : next;
    if (!pick || !Number.isFinite(pick.lat) || !Number.isFinite(pick.lng)) {
      // Fallback to first valid point
      const firstValid = gpsPoints.find(p => Number.isFinite(p.lat) && Number.isFinite(p.lng));
      if (firstValid) return [firstValid.lat, firstValid.lng];
      return null;
    }
    return [pick.lat, pick.lng];
  }, [selectedTime, gpsPoints]);

  const pathPositions = useMemo<[number, number][]>(() => {
    return gpsPoints
      .filter(p => Number.isFinite(p.lat) && Number.isFinite(p.lng) && Math.abs(p.lat) <= 90 && Math.abs(p.lng) <= 180)
      .map(p => [p.lat, p.lng] as [number, number]);
  }, [gpsPoints]);

  // Compute bearing (degrees) between two points
  const bearingDeg = (a: { lat: number; lng: number }, b: { lat: number; lng: number }): number => {
    const φ1 = (a.lat * Math.PI) / 180;
    const φ2 = (b.lat * Math.PI) / 180;
    const Δλ = ((b.lng - a.lng) * Math.PI) / 180;
    const y = Math.sin(Δλ) * Math.cos(φ2);
    const x = Math.cos(φ1) * Math.sin(φ2) - Math.sin(φ1) * Math.cos(φ2) * Math.cos(Δλ);
    const θ = Math.atan2(y, x);
    return (θ * 180) / Math.PI;
  };

  const arrowMarkers = useMemo(() => {
    if (gpsPoints.length < 2) return [] as Array<{ pos: [number, number]; icon: L.DivIcon }>;
    const maxArrows = 20;
    const step = Math.max(1, Math.floor(gpsPoints.length / maxArrows));
    const arr: Array<{ pos: [number, number]; icon: L.DivIcon }> = [];
    for (let i = step; i < gpsPoints.length; i += step) {
      const prev = gpsPoints[i - 1];
      const curr = gpsPoints[i];
      const ang = bearingDeg(prev, curr);
      const icon = L.divIcon({
        className: '',
        html: `<div style="transform: rotate(${ang}deg); width: 0; height: 0; border-left: 6px solid transparent; border-right: 6px solid transparent; border-top: 10px solid #2563eb; opacity: 0.9;"></div>`,
        iconSize: [12, 12],
        iconAnchor: [6, 6],
      });
      arr.push({ pos: [curr.lat, curr.lng], icon });
    }
    return arr;
  }, [gpsPoints]);

  const Recenter: React.FC<{ position: [number, number] | null }> = ({ position }) => {
    const map = useMap();
    React.useEffect(() => {
      if (position && Number.isFinite(position[0]) && Number.isFinite(position[1])) {
        console.log('🗺️ Recentering map to:', position);
        map.setView(position, gpsPoints.length > 0 ? 12 : 10, { animate: true });
      }
    }, [position, map, gpsPoints.length]);
    return null;
  };

  // Debug: Log map rendering state
  React.useEffect(() => {
    console.log('🗺️ Map rendering state:', {
      gpsPointsCount: gpsPoints.length,
      hasCurrentPosition: currentPosition !== null,
      currentPosition,
      pathPositionsCount: pathPositions.length,
      arrowMarkersCount: arrowMarkers.length,
      selectedVehicleId,
      selectedDate
    });
  }, [gpsPoints, currentPosition, pathPositions, arrowMarkers, selectedVehicleId, selectedDate]);

  return (
    <div className={styles.mapContainer}>
      {gpsPoints.length === 0 && selectedVehicleId && selectedDate && (
        <div style={{
          position: 'absolute',
          top: '10px',
          left: '50%',
          transform: 'translateX(-50%)',
          background: 'rgba(255, 193, 7, 0.9)',
          padding: '10px 20px',
          borderRadius: '5px',
          zIndex: 1000,
          fontSize: '14px',
          fontWeight: 'bold',
          color: '#000'
        }}>
          ⚠️ No GPS data available for this vehicle and date
        </div>
      )}
      <MapContainer
        center={currentPosition ?? (pathPositions.length > 0 ? pathPositions[0] : center)}
        zoom={gpsPoints.length > 0 ? 12 : 10}
        className={styles.map}
        zoomControl={true}
        scrollWheelZoom={true}
        doubleClickZoom={true}
        dragging={true}
        key={`map-${gpsPoints.length}-${selectedVehicleId}-${selectedDate}`}
      >
        <TileLayer
          attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
          url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
        />
        {/* Draw the path using full gps track */}
        {pathPositions.length >= 2 && (
          <Polyline positions={pathPositions} pathOptions={{ color: '#2563eb', weight: 4, opacity: 0.8 }} />
        )}
        {/* Recenter to first point if no current position */}
        <Recenter position={currentPosition ?? (pathPositions.length > 0 ? pathPositions[0] : null)} />
        {currentPosition && Number.isFinite(currentPosition[0]) && Number.isFinite(currentPosition[1]) && (
          <Marker position={currentPosition} icon={vehicleIcon}>
            <Popup>
              <div className={styles.popupContent}>
                <h4>Vehicle</h4>
                <p>Location: {currentPosition[0].toFixed(6)}, {currentPosition[1].toFixed(6)}</p>
                <p>Time: {selectedTime ? new Date(selectedTime).toLocaleTimeString() : '—'}</p>
              </div>
            </Popup>
          </Marker>
        )}
        {/* Direction arrows along the route */}
        {arrowMarkers.map((m, idx) => (
          <Marker key={`arr-${idx}`} position={m.pos} icon={m.icon} />
        ))}
        {/* Start and end markers for context */}
        {pathPositions.length >= 2 && (
          <>
            <Marker
              position={pathPositions[0]}
              icon={L.divIcon({
                className: '',
                html: '<div style="width:10px;height:10px;border-radius:50%;background:#10b981;border:2px solid #fff;box-shadow:0 1px 2px rgba(0,0,0,0.3);"></div>',
                iconSize: [12, 12],
                iconAnchor: [6, 6],
              })}
            />
            <Marker
              position={pathPositions[pathPositions.length - 1]}
              icon={L.divIcon({
                className: '',
                html: '<div style="width:10px;height:10px;border-radius:50%;background:#ef4444;border:2px solid #fff;box-shadow:0 1px 2px rgba(0,0,0,0.3);"></div>',
                iconSize: [12, 12],
                iconAnchor: [6, 6],
              })}
            />
          </>
        )}
      </MapContainer>
    </div>
  );
};

export default MapComponent;
