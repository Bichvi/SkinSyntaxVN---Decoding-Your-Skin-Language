export const spacing = {
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 22,
  xxl: 30,
} as const;

export const radius = {
  sm: 8,
  md: 14,
  lg: 20,
  pill: 999,
} as const;

export const palette = {
  light: {
    background: '#F9FBF8', surface: '#FFFFFF', surfaceTint: '#F0F4F1',
    text: '#1A2F1A', muted: '#5C705E', border: '#E2EADF',
    brand: '#2D5A27', brandStrong: '#21431D', secondary: '#4A7C59', accent: '#84A98C',
    warning: '#8A4B16', warningSurface: '#FFF5E8',
  },
  dark: {
    background: '#141D19', surface: '#202D26', surfaceTint: '#26372E',
    text: '#F3F8F4', muted: '#B7C7BE', border: '#394E43',
    brand: '#A1D9B4', brandStrong: '#B7E7C7', secondary: '#A5CEB3', accent: '#79A98A',
    warning: '#F2C28C', warningSurface: '#3A2C1E',
  },
} as const;

export type AppColors = (typeof palette)[keyof typeof palette];
