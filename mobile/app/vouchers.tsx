import { useCallback, useState } from 'react';
import { ActivityIndicator, FlatList, RefreshControl, StyleSheet, Text, View, useColorScheme } from 'react-native';
import Ionicons from '@expo/vector-icons/Ionicons';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useFocusEffect } from 'expo-router';

import { mobileApiRequest } from '@/src/services/mobileApi';
import { palette, radius, spacing } from '@/src/theme/tokens';

type Voucher = { voucher_code: string; campaign_name: string; discount_type: string; discount_value: number; max_discount_amount: number | null; min_order_amount: number; status: string; end_date: string | null };
const money = new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 });

export default function VouchersScreen() {
  const colors = palette[useColorScheme() === 'dark' ? 'dark' : 'light'];
  const [vouchers, setVouchers] = useState<Voucher[]>([]);
  const [loading, setLoading] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const load = useCallback(async (refresh = false) => {
    if (refresh) setRefreshing(true);
    else setLoading(true);
    setError('');
    try { const response = await mobileApiRequest<{ vouchers: Voucher[] }>('account.vouchers'); setVouchers(response.vouchers); }
    catch (cause) { setError(cause instanceof Error ? cause.message : 'Không tải được ví voucher.'); }
    finally { setLoading(false); setRefreshing(false); }
  }, []);
  useFocusEffect(useCallback(() => { void load(); }, [load]));

  return <SafeAreaView edges={['left', 'right']} style={[styles.screen, { backgroundColor: colors.background }]}>
    <FlatList data={vouchers} keyExtractor={(item, index) => `${item.voucher_code}-${index}`} contentContainerStyle={styles.content} refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void load(true)} tintColor={colors.brand} />}
      ListHeaderComponent={<View style={styles.header}><Text accessibilityRole="header" style={[styles.title, { color: colors.text }]}>Ví voucher</Text><Text style={[styles.caption, { color: colors.muted }]}>Ưu đãi đã phát cho tài khoản của bạn.</Text></View>}
      ListEmptyComponent={loading ? <View style={styles.center}><ActivityIndicator color={colors.brand} /><Text style={{ color: colors.muted }}>Đang tải voucher…</Text></View> : <View style={[styles.empty, { backgroundColor: colors.surface, borderColor: colors.border }]}><Ionicons name="ticket-outline" size={30} color={colors.brand} /><Text style={[styles.emptyTitle, { color: colors.text }]}>Chưa có voucher</Text><Text style={[styles.caption, { color: colors.muted, textAlign: 'center' }]}>Voucher nhận được trên SkinSyntax sẽ hiển thị ở đây.</Text></View>}
      renderItem={({ item }) => <View style={[styles.card, { backgroundColor: colors.surface, borderColor: colors.border }]}>
        <View style={styles.cardHead}><View style={[styles.icon, { backgroundColor: colors.surfaceTint }]}><Ionicons name="ticket-outline" size={20} color={colors.brand} /></View><View style={{ flex: 1 }}><Text style={[styles.name, { color: colors.text }]}>{item.campaign_name || 'Ưu đãi SkinSyntax'}</Text><Text style={[styles.code, { color: colors.brand }]}>{item.voucher_code}</Text></View><Text style={[styles.status, { color: item.status === 'AVAILABLE' ? colors.secondary : colors.muted }]}>{statusLabel(item.status)}</Text></View>
        <Text style={[styles.discount, { color: colors.text }]}>{item.discount_type.toLowerCase().includes('percent') ? `Giảm ${item.discount_value}%${item.max_discount_amount ? `, tối đa ${money.format(item.max_discount_amount)}` : ''}` : `Giảm ${money.format(item.discount_value)}`}</Text>
        {item.min_order_amount > 0 && <Text style={[styles.caption, { color: colors.muted }]}>Đơn tối thiểu {money.format(item.min_order_amount)}</Text>}
        {!!item.end_date && <Text style={[styles.caption, { color: colors.muted }]}>Hạn dùng đến {formatDate(item.end_date)}</Text>}
      </View>}
      ListFooterComponent={<>{!!error && <Text accessibilityLiveRegion="polite" style={[styles.message, { color: colors.warning }]}>{error}</Text>}</>}
    />
  </SafeAreaView>;
}

function statusLabel(status: string) { return ({ AVAILABLE: 'Có thể dùng', USED: 'Đã dùng', EXPIRED: 'Hết hạn' } as Record<string, string>)[status] ?? status; }
function formatDate(value: string) { const date = new Date(value); return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat('vi-VN', { dateStyle: 'medium' }).format(date); }

const styles = StyleSheet.create({ screen: { flex: 1 }, content: { width: '100%', maxWidth: 740, alignSelf: 'center', padding: spacing.lg, paddingBottom: 36, gap: spacing.md }, header: { marginBottom: 4 }, title: { fontSize: 27, fontWeight: '800' }, caption: { fontSize: 13, lineHeight: 19, marginTop: 3 }, center: { alignItems: 'center', paddingVertical: 48, gap: 10 }, empty: { alignItems: 'center', gap: spacing.sm, borderWidth: 1, borderRadius: radius.lg, padding: spacing.xl }, emptyTitle: { fontSize: 17, fontWeight: '800' }, card: { borderWidth: 1, borderRadius: radius.lg, padding: spacing.md, gap: 8, marginBottom: spacing.sm }, cardHead: { flexDirection: 'row', alignItems: 'center', gap: 10 }, icon: { width: 42, height: 42, alignItems: 'center', justifyContent: 'center', borderRadius: radius.md }, name: { fontSize: 14, fontWeight: '800' }, code: { fontSize: 12, fontWeight: '800', letterSpacing: 0.7, marginTop: 2 }, status: { fontSize: 11, fontWeight: '800' }, discount: { fontSize: 15, fontWeight: '800' }, message: { fontSize: 13, lineHeight: 19 },
});
