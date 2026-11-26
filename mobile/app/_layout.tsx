import { useFonts } from 'expo-font';
import { DarkTheme, DefaultTheme, ThemeProvider } from '@react-navigation/native';
import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { useEffect } from 'react';
import 'react-native-reanimated';

import { useColorScheme } from '@/components/useColorScheme';
import { MobileAuthProvider } from '@/src/auth/MobileAuthProvider';

export {
  // Catch any errors thrown by the Layout component.
  ErrorBoundary,
} from 'expo-router';

export const unstable_settings = {
  // Ensure that reloading on `/modal` keeps a back button present.
  initialRouteName: '(tabs)',
};

// Prevent the splash screen from auto-hiding before asset loading is complete.
SplashScreen.preventAutoHideAsync();

export default function RootLayout() {
  const [loaded, error] = useFonts({
    SpaceMono: require('../assets/fonts/SpaceMono-Regular.ttf'),
  });

  // Expo Router uses Error Boundaries to catch errors in the navigation tree.
  useEffect(() => {
    if (error) throw error;
  }, [error]);

  useEffect(() => {
    if (loaded) {
      SplashScreen.hideAsync();
    }
  }, [loaded]);

  if (!loaded) {
    return null;
  }

  return <RootLayoutNav />;
}

function RootLayoutNav() {
  const colorScheme = useColorScheme();

  return (
    <MobileAuthProvider>
      <ThemeProvider value={colorScheme === 'dark' ? DarkTheme : DefaultTheme}>
        <Stack>
          <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
          <Stack.Screen name="assistant" options={{ headerShown: false, presentation: 'card' }} />
          <Stack.Screen name="modal" options={{ presentation: 'modal' }} />
          <Stack.Screen name="sign-in" options={{ title: 'Đăng nhập' }} />
          <Stack.Screen name="profile" options={{ title: 'Hồ sơ cá nhân' }} />
          <Stack.Screen name="skin-profile" options={{ title: 'Hồ sơ da' }} />
          <Stack.Screen name="addresses" options={{ title: 'Địa chỉ giao hàng' }} />
          <Stack.Screen name="orders" options={{ title: 'Đơn hàng' }} />
          <Stack.Screen name="favorites" options={{ title: 'Yêu thích' }} />
          <Stack.Screen name="vouchers" options={{ title: 'Ví voucher' }} />
          <Stack.Screen name="loyalty" options={{ title: 'Điểm thành viên' }} />
        </Stack>
      </ThemeProvider>
    </MobileAuthProvider>
  );
}
