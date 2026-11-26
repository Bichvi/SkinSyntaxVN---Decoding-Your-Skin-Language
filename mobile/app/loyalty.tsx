import { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View, useColorScheme } from 'react-native';
import Ionicons from '@expo/vector-icons/Ionicons';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useFocusEffect } from 'expo-router';

import { mobileApiRequest } from '@/src/services/mobileApi';
import { palette, radius, spacing } from '@/src/theme/tokens';

type Loyalty = { points: number; level: string };
const levelNames: Record<string, string> = { Thuong: 'Thường', Bac: 'Bạc', Vang: 'Vàng', KimCuong: 'Kim cương' };

export default function LoyaltyScreen() {
  const colors = palette[useColorScheme() === 'dark' ? 'dark' : 'light'];
  const [loyalty, setLoyalty] = useState<Loyalty | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const load = useCallback(async () => {
    setLoading(true); setError('');
    try { const response = await mobileApiRequest<{ loyalty: Loyalty }>('account.loyalty'); setLoyalty(response.loyalty); }
    catch (cause) { setError(cause instanceof Error ? cause.message : 'Không tải được điểm thành viên.'); }
    finally { setLoading(false); }
  }, []);
  useFocusEffect(useCallback(() => { void load(); }, [load]));

  return <SafeAreaView edges={['left', 'right']} style={[styles.screen, { backgroundColor: colors.background }]}>
    <View style={styles.content}>
      <Text accessibilityRole="header" style={[styles.title, { color: colors.text }]}>Điểm thành viên</Text>
      <Text style={[styles.caption, { color: colors.muted }]}>Điểm và hạng được đọc từ hồ sơ khách hàng trên SkinSyntax.</Text>
      {loading ? <View style={styles.center}><ActivityIndicator color={colors.brand} /><Text style={{ color: colors.muted }}>Đang tải điểm…</Text></View> : loyalty && <View style={[styles.card, { backgroundColor: colors.surfaceTint }]}>
        <View style={[styles.badge, { backgroundColor: colors.surface }]}><Ionicons name="ribbon-outline" size={27} color={colors.brand} /></View>
        <Text style={[styles.points, { color: colors.text }]}>{new Intl.NumberFormat('vi-VN').format(loyalty.points)}</Text>
        <Text style={[styles.pointsLabel, { color: colors.muted }]}>điểm tích lũy</Text>
        <View style={[styles.level, { backgroundColor: colors.surface }]}><Text style={[styles.levelLabel, { color: colors.brand }]}>{levelNames[loyalty.level] ?? loyalty.level}</Text></View>
      </View>}
      {!!error && <Text accessibilityLiveRegion="polite" style={[styles.message, { color: colors.warning }]}>{error}</Text>}
      <Pressable accessibilityRole="button" disabled={loading} onPress={() => void load()} style={[styles.refresh, { borderColor: colors.border }]}><Ionicons name="refresh-outline" size={17} color={colors.brand} /><Text style={[styles.refreshText, { color: colors.brand }]}>Cập nhật điểm</Text></Pressable>
    </View>
  </SafeAreaView>;
}

const styles = StyleSheet.create({ screen: { flex: 1 }, content: { width: '100%', maxWidth: 620, alignSelf: 'center', padding: spacing.lg, gap: spacing.md }, title: { fontSize: 27, fontWeight: '800' }, caption: { fontSize: 13, lineHeight: 19 }, center: { alignItems: 'center', paddingVertical: 48, gap: 10 }, card: { alignItems: 'center', borderRadius: radius.lg, padding: spacing.xl, gap: 6, marginTop: spacing.sm }, badge: { width: 56, height: 56, alignItems: 'center', justifyContent: 'center', borderRadius: radius.pill, marginBottom: 8 }, points: { fontSize: 38, lineHeight: 46, fontWeight: '900' }, pointsLabel: { fontSize: 13 }, level: { borderRadius: radius.pill, paddingHorizontal: 15, paddingVertical: 7, marginTop: 8 }, levelLabel: { fontSize: 13, fontWeight: '800' }, refresh: { minHeight: 46, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, borderWidth: 1, borderRadius: radius.md }, refreshText: { fontSize: 13, fontWeight: '800' }, message: { fontSize: 13, lineHeight: 19 },
});
