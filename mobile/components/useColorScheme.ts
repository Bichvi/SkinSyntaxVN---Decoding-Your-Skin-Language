import { useColorScheme as useColorSchemeCore } from 'react-native';

export const useColorScheme = () => useColorSchemeCore() === 'dark' ? 'dark' : 'light';
