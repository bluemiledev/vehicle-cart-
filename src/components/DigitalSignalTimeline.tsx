import React, { useMemo, useRef, useEffect, useState } from 'react';
import {
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  ReferenceLine,
  ResponsiveContainer
} from 'recharts';
import { format } from 'date-fns';
import styles from './DigitalSignalTimeline.module.css';

interface DigitalSignalData {
  id: string;
  name: string;
  color: string;
  data: Array<{ time: Date; value: number }>;
  currentValue: number;
}

interface DigitalSignalTimelineProps {
  signals: DigitalSignalData[];
  selectedTime: Date | null;
  crosshairActive: boolean;
  timeDomain: [number, number] | null; // Timestamp range for synchronization
}

interface ChartDataPoint {
  time: number; // Timestamp for Recharts time scale
  [key: string]: number | undefined;
}

const DigitalSignalTimeline: React.FC<DigitalSignalTimelineProps> = ({
  signals,
  selectedTime,
  crosshairActive,
  timeDomain
}) => {
  // Dynamically size chart height based on number of signals to avoid overlap
  const chartHeight = useMemo(() => {
    const perRow = 30; // px per signal row for clearer spacing
    const minH = 260;  // minimum height when few signals
    const maxH = 1200; // safety cap
    const desired = Math.max(minH, signals.length * perRow);
    return Math.min(maxH, desired);
  }, [signals.length]);
  const chartData = useMemo(() => {
    if (!signals.length || !signals[0].data.length) return [];

    // Determine visible range
    const PAD = 5 * 60 * 1000; // 5 min padding
    const [startTs, endTs] = timeDomain || [Number.NEGATIVE_INFINITY, Number.POSITIVE_INFINITY];
    const lo = startTs - PAD;
    const hi = endTs + PAD;

    // Collect ALL unique timestamps from ALL signals (no decimation)
    const allTimesSet = new Set<number>();
    signals.forEach(signal => {
      signal.data.forEach(d => {
        const t = d.time.getTime();
        if (t >= lo && t <= hi) {
          allTimesSet.add(t);
        }
      });
    });

    // Convert to sorted array
    const allTimes = Array.from(allTimesSet).sort((a, b) => a - b);

    // Build data points using EXACT API values (no nearest neighbor interpolation)
    const dataMap = new Map<number, ChartDataPoint>();
    
    allTimes.forEach(t => {
      const point: ChartDataPoint = { time: t };
      signals.forEach((signal, index) => {
        // Find exact match first, or use the exact value at that timestamp
        const exactMatch = signal.data.find(d => d.time.getTime() === t);
        if (exactMatch) {
          point[signal.id] = exactMatch.value === 1 ? index : index - 0.5;
        } else {
          // If no exact match, find the closest point (but don't interpolate)
          let closest = signal.data[0];
          let minDiff = Math.abs(closest.time.getTime() - t);
          for (const s of signal.data) {
            const diff = Math.abs(s.time.getTime() - t);
            if (diff < minDiff) {
              minDiff = diff;
              closest = s;
            }
          }
          // Only use if within 30 seconds (half a minute)
          if (minDiff <= 30000) {
            point[signal.id] = closest.value === 1 ? index : index - 0.5;
          }
        }
      });
      dataMap.set(t, point);
    });

    // Return sorted by timestamp (already sorted from allTimes, but ensure)
    return Array.from(dataMap.values()).sort((a, b) => a.time! - b.time!);
  }, [signals, timeDomain]);

  const formatTime = (tickItem: number) => {
    return format(new Date(tickItem), 'HH:mm');
  };

  // Uniform ticks every 10 minutes
  const ticks = React.useMemo(() => {
    if (!timeDomain) return undefined;
    const [start, end] = timeDomain;
    const step = 10 * 60 * 1000;
    const alignedStart = Math.floor(start / step) * step;
    const arr: number[] = [];
    for (let t = alignedStart; t <= end; t += step) arr.push(t);
    return arr;
  }, [timeDomain]);

  const formatYAxisLabel = (value: number) => {
    // Y-axis ticks are at index - 0.25, so we need to round up to get the correct signal index
    const signalIndex = Math.round(value + 0.25);
    const signal = signals[signalIndex];
    if (!signal) return '';
    return `${signal.name}`;
  };

  const getSignalStatus = (signal: DigitalSignalData): string => {
    if (!selectedTime) {
      // Use the last value in the data array if available, otherwise use currentValue
      const lastValue = signal.data.length > 0 
        ? signal.data[signal.data.length - 1].value 
        : signal.currentValue;
      const isOn = Number(lastValue) === 1;
      // Debug log for Jib Chain Fault
      if (signal.name === 'Jib Chain Fault') {
        console.log('🔍 Jib Chain Fault Status Check:', {
          name: signal.name,
          currentValue: signal.currentValue,
          lastDataPoint: signal.data.length > 0 ? signal.data[signal.data.length - 1] : null,
          lastValue,
          isOn,
          status: isOn ? 'ON' : 'OFF',
          allValues: signal.data.slice(-5).map(d => d.value)
        });
      }
      return isOn ? 'ON' : 'OFF';
    }
    const point = signal.data.find(d => 
      Math.abs(d.time.getTime() - selectedTime.getTime()) < 900000 // 15 min tolerance
    );
    const value = point?.value ?? signal.currentValue;
    const isOn = Number(value) === 1;
    // Debug log for Jib Chain Fault
    if (signal.name === 'Jib Chain Fault') {
      console.log('🔍 Jib Chain Fault Status Check (with selectedTime):', {
        name: signal.name,
        selectedTime,
        point,
        value,
        isOn,
        status: isOn ? 'ON' : 'OFF'
      });
    }
    return isOn ? 'ON' : 'OFF';
  };

  // Hover tooltip removed per requirement

  const containerRef = useRef<HTMLDivElement | null>(null);
  const [size, setSize] = useState<{ w: number; h: number }>({ w: 0, h: 0 });
  useEffect(() => {
    const update = () => {
      const rect = containerRef.current?.getBoundingClientRect();
      setSize({ w: rect?.width || 0, h: rect?.height || 0 });
    };
    update();
    window.addEventListener('resize', update);
    return () => window.removeEventListener('resize', update);
  }, []);

  if (!signals.length || !chartData.length) {
    return (
      <div className={styles.container}>
        <div className={styles.noData}>No digital signal data available</div>
      </div>
    );
  }

  return (
    <div className={styles.container}>
      <div className={styles.chartRow}>
        <div className={styles.chartArea}>
          <div className={styles.chartWrapper} ref={containerRef} style={{ height: chartHeight }}>
            <ResponsiveContainer width="100%" height={chartHeight}>
              <LineChart
                data={chartData}
                margin={{ top: 10, right: 10, left: 20, bottom: 40 }}
              >
                <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
                <XAxis
                  dataKey="time"
                  type="number"
                  scale="time"
                  domain={timeDomain || ['dataMin', 'dataMax']}
                  ticks={ticks as any}
                  tickFormatter={formatTime}
                  stroke="#6b7280"
                  tick={{ fill: '#6b7280', fontSize: 9 }}
                  style={{ fontSize: '9px' }}
                />
                <YAxis
                  type="number"
                  domain={[-0.5, signals.length - 0.5]}
                  ticks={signals.map((_, index) => index - 0.25)}
                  tickFormatter={formatYAxisLabel}
                  stroke="#6b7280"
                  tick={{ fill: '#6b7280', fontSize: 9 }}
                  width={140}
                />
                {/* Tooltip removed */}
                {crosshairActive && selectedTime && (
                  <ReferenceLine
                    x={selectedTime.getTime()}
                    stroke="#ef4444"
                    strokeWidth={2}
                    strokeDasharray="3 3"
                  />
                )}
                {signals.map((signal) => (
                  <Line
                    key={signal.id}
                    type="stepAfter"
                    dataKey={signal.id}
                    stroke={signal.color}
                    strokeWidth={2.5}
                    dot={false}
                    activeDot={{ r: 5, fill: signal.color }}
                    connectNulls={true}
                    isAnimationActive={false}
                  />
                ))}
              </LineChart>
            </ResponsiveContainer>
            <div className={styles.overlay}>
              {signals.map((signal, idx) => {
                const status = getSignalStatus(signal);
                const top = 20; // chart margin top
                const bottom = 40; // chart margin bottom
                const h = size.h > 0 ? size.h : chartHeight; // fallback height matches dynamic height
                const innerH = Math.max(1, h - top - bottom);
                // Y-axis domain is [-0.5, signals.length - 0.5]
                // Signal center is at idx - 0.25
                // Convert Y-axis value to pixel: pixelY = top + ((yAxisValue + 0.5) / signals.length) * innerH
                const yAxisCenter = idx - 0.25;
                const y = top + ((yAxisCenter + 0.5) / signals.length) * innerH;                
                return (
                  <div
                    key={signal.id}
                    className={`${styles.rowStatus} ${status === 'ON' ? styles.on : styles.off}`}
                    style={{ top: y - 8 }}
                  >
                    {status}
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default DigitalSignalTimeline;
