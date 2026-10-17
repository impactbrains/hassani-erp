/** Named colours used by tags and work shifts. */
export const namedColors: Record<string, string> = { amber: '#B7820F', indigo: '#3E5C9A', emerald: '#2F7D55', rose: '#B5473A', sky: '#2D7FA6', slate: '#5B6676' };
export const colorHex = (name: string | null | undefined) => (name && name.startsWith('#') ? name : namedColors[name ?? ''] ?? namedColors.indigo);
