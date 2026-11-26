import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import Ionicons from '@expo/vector-icons/Ionicons';
import { openBrowserAsync } from 'expo-web-browser';

import { buildApiUrl, routes } from '@/src/config/api';
import type { AppColors } from '@/src/theme/tokens';
import { radius } from '@/src/theme/tokens';

type WebsiteRoute = (typeof routes)[keyof typeof routes];

export function WebsiteDestination({ colors, route, title, description, icon, params }: {
  colors: AppColors;
  route: WebsiteRoute;
  title: string;
  description: string;
  icon: React.ComponentProps<typeof Ionicons>['name'];
  params?: Record<string, string>;
}) {
  const [error, setError] = useState('');
  return <View>
    <Pressable accessibilityRole="button" onPress={async () => {
      try { setError(''); await openBrowserAsync(buildApiUrl(route, params)); }
      catch { setError('Không mở được trang. Hãy thử lại.'); }
    }} style={({ pressed }) => [styles.row, { backgroundColor: colors.surface, borderColor: colors.border, opacity: pressed ? 0.78 : 1 }]}>
      <View style={[styles.icon, { backgroundColor: colors.surfaceTint }]}><Ionicons name={icon} size={21} color={colors.brand} /></View>
      <View style={styles.copy}><Text style={[styles.title, { color: colors.text }]}>{title}</Text><Text style={[styles.description, { color: colors.muted }]}>{description}</Text></View>
      <Ionicons name="chevron-forward" size={19} color={colors.muted} />
    </Pressable>
    {!!error && <Text accessibilityLiveRegion="polite" style={[styles.error, { color: colors.muted }]}>{error}</Text>}
  </View>;
}

const styles = StyleSheet.create({
  row: { minHeight: 74, flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 13, paddingVertical: 10, borderWidth: 1, borderRadius: radius.md },
  icon: { width: 42, height: 42, alignItems: 'center', justifyContent: 'center', borderRadius: 14 },
  copy: { flex: 1, gap: 3 }, title: { fontSize: 14, fontWeight: '800' }, description: { fontSize: 12, lineHeight: 17 }, error: { fontSize: 12, marginHorizontal: 12, marginTop: 4 },
});
