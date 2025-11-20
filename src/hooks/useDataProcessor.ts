import { useCallback, useRef } from 'react';
import { processRawData, getWindowedData, clearDataCache } from '../utils/dataProcessor';

interface ProcessedData {
  analogMetrics: any[];
  digitalMetrics: any[];
  gpsData: any[];
  timestamps: number[];
}

interface UseDataProcessorReturn {
  processData: (
    rawData: any,
    selectedDate: string,
    isSecondView: boolean,
    windowStart?: number,
    windowEnd?: number
  ) => Promise<ProcessedData>;
  getWindow: (
    windowStart: number,
    windowEnd: number,
    isSecondView: boolean
  ) => Promise<ProcessedData>;
  clearCache: () => void;
}

export const useDataProcessor = (): UseDataProcessorReturn => {
  // TODO: Move to Web Worker for better performance with very large datasets
  // For now, using direct processing with requestAnimationFrame for non-blocking updates
  const processingRef = useRef<boolean>(false);

  // Process data asynchronously using requestAnimationFrame to avoid blocking UI
  const processDataAsync = (fn: () => ProcessedData): Promise<ProcessedData> => {
    return new Promise((resolve) => {
      if (processingRef.current) {
        // If already processing, queue this request
        requestAnimationFrame(() => {
          resolve(processDataAsync(fn));
        });
        return;
      }
      
      processingRef.current = true;
      requestAnimationFrame(() => {
        try {
          const result = fn();
          processingRef.current = false;
          resolve(result);
        } catch (error) {
          processingRef.current = false;
          throw error;
        }
      });
    });
  };

  const processData = useCallback(
    (
      rawData: any,
      selectedDate: string,
      isSecondView: boolean,
      windowStart?: number,
      windowEnd?: number
    ): Promise<ProcessedData> => {
      return processDataAsync(() => {
        // Process raw data (cached internally)
        const processed = processRawData(rawData, selectedDate);
        
        // Get windowed data if window specified
        if (windowStart != null && windowEnd != null) {
          return getWindowedData(windowStart, windowEnd, isSecondView);
        } else {
          // Return full dataset
          if (processed.timestamps.length === 0) {
            return {
              analogMetrics: [],
              digitalMetrics: [],
              gpsData: [],
              timestamps: [],
            };
          }
          // For second view, return full data without downsampling (all per-second points)
          // For minute view, use normal downsampling
          const firstTimestamp = processed.timestamps[0];
          const lastTimestamp = processed.timestamps[processed.timestamps.length - 1];
          return getWindowedData(firstTimestamp, lastTimestamp, isSecondView);
        }
      });
    },
    []
  );

  const getWindow = useCallback(
    (windowStart: number, windowEnd: number, isSecondView: boolean): Promise<ProcessedData> => {
      return processDataAsync(() => {
        return getWindowedData(windowStart, windowEnd, isSecondView);
      });
    },
    []
  );

  const clearCache = useCallback(() => {
    clearDataCache();
  }, []);

  return {
    processData,
    getWindow,
    clearCache,
  };
};

