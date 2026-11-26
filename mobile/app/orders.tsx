import { useCallback, useState } from 'react';
import { ActivityIndicator, FlatList, RefreshControl, StyleSheet, Text, View, useColorScheme } from 'react-native';
import Ionicons from '@expo/vector-icons/Ionicons';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useFocusEffect } from 'expo-router';

import { mobileApiRequest } from '@/src/services/mobileApi';
import { ProductImage } from '@/src/components/ProductImage';
import { palette, radius, spacing } from '@/src/theme/tokens';

type OrderItem = { product_id: string; name: string; image_url: string; quantity: number; unit_price: number };
type Order = { id: string; placed_at: string | null; status: string; total: number; payment_method: string; items: OrderItem[] };
const money = new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' });

export default function OrdersScreen() {
  const colors = palette[useColorScheme() === 'dark' ? 'dark' : 'light'];
  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const load = useCallback(async (refresh = false) => {
    if (refresh) setRefreshing(true);
    else setLoading(true);
    setError('');
    try { const response = await mobileApiRequest<{ orders: Order[] }>('account.orders'); setOrders(response.orders); }
    catch (cause) { setError(cause instanceof Error ? cause.message : 'Không tải được đơn hàng.'); }
    finally { setLoading(false); setRefreshing(false); }
  }, []);
  useFocusEffect(useCallback(() => { void load(); }, [load]));

  return <SafeAreaView edges={['left', 'right']} style={[styles.screen, { backgroundColor: colors.background }]}>
    <FlatList data={orders} keyExtractor={item => item.id} contentContainerStyle={styles.content} refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void load(true)} tintColor={colors.brand} />}
      ListHeaderComponent={<View style={styles.header}><Text accessibilityRole="header" style={[styles.title, { color: colors.text }]}>Đơn hàng</Text><Text style={[styles.caption, { color: colors.muted }]}>Lịch sử đơn hàng từ tài khoản SkinSyntax.</Text></View>}
      ListEmptyComponent={loading ? <View style={styles.center}><ActivityIndicator color={colors.brand} /><Text style={{ color: colors.muted }}>Đang tải đơn hàng…</Text></View> : <View style={[styles.empty, { backgroundColor: colors.surface, borderColor: colors.border }]}><Ionicons name="receipt-outline" size={30} color={colors.brand} /><Text style={[styles.emptyTitle, { color: colors.text }]}>Chưa có đơn hàng</Text><Text style={[styles.caption, { color: colors.muted, textAlign: 'center' }]}>Đơn hàng đã đặt sẽ xuất hiện tại đây.</Text></View>}
      renderItem={({ item }) => <View style={[styles.order, { backgroundColor: colors.surface, borderColor: colors.border }]}>
        <View style={styles.orderHeader}><View style={{ flex: 1 }}><Text style={[styles.orderId, { color: colors.text }]}>Đơn #{item.id}</Text><Text style={[styles.caption, { color: colors.muted }]}>{formatDate(item.placed_at)}</Text></View><Text style={[styles.status, { color: colors.secondary, backgroundColor: colors.surfaceTint }]}>{item.status || 'Đang xử lý'}</Text></View>
        {item.items.map((line, index) => <View key={`${line.product_id}-${index}`} style={styles.item}><ProductImage uris={[line.image_url]} colors={colors} size="small" /><View style={{ flex: 1, gap: 3 }}><Text numberOfLines={2} style={[styles.itemName, { color: colors.text }]}>{line.name || `Sản phẩm ${line.product_id}`}</Text><Text style={[styles.caption, { color: colors.muted }]}>{line.quantity} × {money.format(line.unit_price)}</Text></View></View>)}
        <View style={[styles.totalRow, { borderTopColor: colors.border }]}><Text style={[styles.caption, { color: colors.muted }]}>{item.payment_method || 'Tổng đơn hàng'}</Text><Text style={[styles.total, { color: colors.brand }]}>{money.format(item.total)}</Text></View>
      </View>}
      ListFooterComponent={<>{!!error && <Text accessibilityLiveRegion="polite" style={[styles.message, { color: colors.warning }]}>{error}</Text>}</>}
    />
  </SafeAreaView>;
}

function formatDate(value: string | null): string {
  if (!value) return 'Ngày đặt chưa có thông tin';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat('vi-VN', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}

const styles = StyleSheet.create({ screen: { flex: 1 }, content: { width: '100%', maxWidth: 760, alignSelf: 'center', padding: spacing.lg, paddingBottom: 36, gap: spacing.md }, header: { marginBottom: 5 }, title: { fontSize: 27, fontWeight: '800' }, caption: { fontSize: 12, lineHeight: 18, marginTop: 3 }, center: { alignItems: 'center', paddingVertical: 48, gap: 10 }, empty: { alignItems: 'center', gap: spacing.sm, borderWidth: 1, borderRadius: radius.lg, padding: spacing.xl }, emptyTitle: { fontSize: 17, fontWeight: '800' }, order: { borderWidth: 1, borderRadius: radius.lg, padding: spacing.md, gap: 10, marginBottom: spacing.sm }, orderHeader: { flexDirection: 'row', alignItems: 'center', gap: 8 }, orderId: { fontSize: 15, fontWeight: '800' }, status: { fontSize: 11, fontWeight: '800', overflow: 'hidden', paddingHorizontal: 9, paddingVertical: 5, borderRadius: radius.pill }, item: { flexDirection: 'row', alignItems: 'center', gap: 10 }, itemName: { fontSize: 13, fontWeight: '700', lineHeight: 18 }, totalRow: { borderTopWidth: StyleSheet.hairlineWidth, paddingTop: 10, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }, total: { fontSize: 15, fontWeight: '800' }, message: { fontSize: 13, lineHeight: 19 },
});
