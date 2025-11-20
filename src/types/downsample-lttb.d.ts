declare module 'downsample-lttb' {
  export function processData(
    series: Array<[number, number]>,
    threshold: number
  ): Array<[number, number]>;
}

