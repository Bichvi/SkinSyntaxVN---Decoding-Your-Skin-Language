import { SymbolView, type SymbolViewProps } from 'expo-symbols';
import { Tabs } from 'expo-router';
import type { ColorValue } from 'react-native';

import Colors from '@/constants/Colors';
import { useColorScheme } from '@/components/useColorScheme';
import { useClientOnlyValue } from '@/components/useClientOnlyValue';

function TabIcon({ name, color }: { name: SymbolViewProps['name']; color: ColorValue }) {
  return <SymbolView name={name} tintColor={color} size={25} />;
}

export default function TabLayout() {
  const colorScheme = useColorScheme();

  return (
    <Tabs
      screenOptions={{
        tabBarActiveTintColor: Colors[colorScheme].tint,
        tabBarInactiveTintColor: Colors[colorScheme].tabIconDefault,
        tabBarStyle: {
          borderTopColor: '#D9E7DE',
        },
        // Disable the static render of the header on web
        // to prevent a hydration error in React Navigation v6.
        headerShown: useClientOnlyValue(false, true),
      }}>
      <Tabs.Screen
        name="index"
        options={{
          title: 'Trang chủ',
          tabBarIcon: ({ color }) => (
            <TabIcon
              name={{
                ios: 'house.fill',
                android: 'home',
                web: 'home',
              }}
              color={color}
            />
          ),
        }}
      />
      <Tabs.Screen
        name="explore"
        options={{
          title: 'Khám phá',
          tabBarIcon: ({ color }) => (
            <TabIcon
              name={{
                ios: 'magnifyingglass',
                android: 'search',
                web: 'search',
              }}
              color={color}
            />
          ),
        }}
      />
      <Tabs.Screen
        name="routine"
        options={{
          title: 'Routine',
          tabBarIcon: ({ color }) => (
            <TabIcon
              name={{
                ios: 'sparkles',
                android: 'routine',
                web: 'auto_awesome',
              }}
              color={color}
            />
          ),
        }}
      />
      <Tabs.Screen
        name="cart"
        options={{
          title: 'Giỏ hàng',
          tabBarIcon: ({ color }) => (
            <TabIcon
              name={{
                ios: 'bag.fill',
                android: 'shopping_bag',
                web: 'shopping_bag',
              }}
              color={color}
            />
          ),
        }}
      />
      <Tabs.Screen
        name="account"
        options={{
          title: 'Tài khoản',
          tabBarIcon: ({ color }) => (
            <TabIcon
              name={{
                ios: 'person.crop.circle.fill',
                android: 'account_circle',
                web: 'account_circle',
              }}
              color={color}
            />
          ),
        }}
      />
    </Tabs>
  );
}
