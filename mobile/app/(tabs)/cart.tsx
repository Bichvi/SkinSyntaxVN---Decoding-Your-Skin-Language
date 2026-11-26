import { useCallback, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, StyleSheet, Text, View, useColorScheme } from 'react-native';
import Ionicons from '@expo/vector-icons/Ionicons';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useFocusEffect, useRouter } from 'expo-router';

import { useMobileAuth } from '@/src/auth/MobileAuthProvider';
import { mobileApiRequest, type CartLine, type CustomerCart } from '@/src/services/mobileApi';
import { ProductImage } from '@/src/components/ProductImage';
import { palette, radius, spacing } from '@/src/theme/tokens';

const money = new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' });

export default function CartScreen() {
  const colors = palette[useColorScheme() === 'dark' ? 'dark' : 'light'];
  const { user } = useMobileAuth();
  const router = useRouter();
  const [cart, setCart] = useState<CustomerCart | null>(null);
  const [loading, setLoading] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const [busyProduct, setBusyProduct] = useState('');

  const loadCart = useCallback(async (refresh = false) => {
    if (!user) { setCart(null); return; }
    if (refresh) setRefreshing(true);
    else setLoading(true);
    setError('');
    try {
      const response = await mobileApiRequest<{ cart: CustomerCart }>('cart.get');
      setCart(response.cart);
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Không tải được giỏ hàng.'); }
    finally { setLoading(false); setRefreshing(false); }
  }, [user]);

  useFocusEffect(useCallback(() => { void loadCart(); }, [loadCart]));

  const mutate = async (line: CartLine, quantity: number) => {
    setBusyProduct(line.product_id); setError('');
    try {
      const response = quantity <= 0
        ? await mobileApiRequest<{ cart: CustomerCart }>('cart.remove', { method: 'DELETE', body: { product_id: line.product_id } })
        : await mobileApiRequest<{ cart: CustomerCart }>('cart.set', { method: 'PUT', body: { product_id: line.product_id, quantity } });
      setCart(response.cart);
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Chưa thể cập nhật số lượng.');
      await loadCart(true);
    } finally { setBusyProduct(''); }
  };

  if (!user) return <SafeAreaView edges={['left', 'right']} style={[styles.screen, { backgroundColor: colors.background }]}>
    <View style={styles.gate}>
      <View style={[styles.icon, { backgroundColor: colors.surfaceTint }]}><Ionicons name="bag-handle-outline" size={27} color={colors.brand} /></View>
      <Text accessibilityRole="header" style={[styles.title, { color: colors.text }]}>Giỏ hàng theo tài khoản</Text>
      <Text style={[styles.body, { color: colors.muted }]}>Đăng nhập để tải giỏ hàng đã đồng bộ với website SkinSyntaxVN.</Text>
      <Pressable accessibilityRole="button" onPress={() => router.push('/sign-in')} style={({ pressed }) => [styles.primary, { backgroundColor: colors.brandStrong, opacity: pressed ? 0.8 : 1 }]}><Text style={styles.primaryText}>Đăng nhập</Text></Pressable>
    </View>
  </SafeAreaView>;

  return <SafeAreaView edges={['left', 'right']} style={[styles.screen, { backgroundColor: colors.background }]}>
    <FlatList data={cart?.items ?? []} keyExtractor={item => item.product_id} contentContainerStyle={styles.content}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void loadCart(true)} tintColor={colors.brand} />}
      ListHeaderComponent={<View style={styles.header}><Text accessibilityRole="header" style={[styles.title, { color: colors.text }]}>Giỏ hàng</Text><Text style={[styles.body, { color: colors.muted }]}>Tải lại từ tài khoản mỗi lần mở màn hình.</Text></View>}
      ListEmptyComponent={loading ? <View style={styles.status}><ActivityIndicator color={colors.brand} /><Text style={{ color: colors.muted }}>Đang tải giỏ hàng…</Text></View> : <View style={[styles.empty, { backgroundColor: colors.surface, borderColor: colors.border }]}><Ionicons name="cart-outline" size={30} color={colors.brand} /><Text style={[styles.emptyTitle, { color: colors.text }]}>Chưa có sản phẩm</Text><Text style={[styles.body, { color: colors.muted, textAlign: 'center' }]}>Sản phẩm thêm trên website hoặc ứng dụng sẽ xuất hiện ở đây.</Text><Pressable accessibilityRole="button" onPress={() => router.push('/(tabs)/explore')} style={styles.retry}><Text style={{ color: colors.brand, fontWeight: '700' }}>Khám phá sản phẩm</Text></Pressable></View>}
      renderItem={({ item }) => <View style={[styles.line, { backgroundColor: colors.surface, borderColor: colors.border }]}>
        <ProductImage uris={[item.product.image_url]} colors={colors} size="medium" />
        <View style={styles.lineCopy}>
          {!!item.product.brand && <Text numberOfLines={1} style={[styles.brand, { color: colors.muted }]}>{item.product.brand}</Text>}
          <Text numberOfLines={2} style={[styles.productName, { color: colors.text }]}>{item.product.name}</Text>
          <Text style={[styles.price, { color: colors.brand }]}>{money.format(item.unit_price)}</Text>
          <View style={styles.controls}>
            <Pressable accessibilityRole="button" accessibilityLabel={`Giảm ${item.product.name}`} disabled={busyProduct === item.product_id} onPress={() => void mutate(item, item.quantity - 1)} style={[styles.stepper, { borderColor: colors.border }]}><Ionicons name="remove" size={18} color={colors.brand} /></Pressable>
            <Text accessibilityLabel={`Số lượng ${item.quantity}`} style={[styles.quantity, { color: colors.text }]}>{item.quantity}</Text>
            <Pressable accessibilityRole="button" accessibilityLabel={`Tăng ${item.product.name}`} disabled={busyProduct === item.product_id || item.quantity >= item.stock} onPress={() => void mutate(item, item.quantity + 1)} style={[styles.stepper, { borderColor: colors.border, opacity: item.quantity >= item.stock ? 0.45 : 1 }]}><Ionicons name="add" size={18} color={colors.brand} /></Pressable>
            <Pressable accessibilityRole="button" accessibilityLabel={`Xóa ${item.product.name}`} disabled={busyProduct === item.product_id} onPress={() => void mutate(item, 0)} style={styles.delete}><Ionicons name="trash-outline" size={18} color={colors.warning} /></Pressable>
          </View>
        </View>
      </View>}
      ListFooterComponent={<>
        {!!error && <Text accessibilityLiveRegion="polite" style={[styles.error, { color: colors.warning }]}>{error}</Text>}
        {cart && cart.items.length > 0 && <View style={[styles.summary, { backgroundColor: colors.surface, borderColor: colors.border }]}>
          <View style={styles.summaryRow}><Text style={[styles.body, { color: colors.muted }]}>Tạm tính ({cart.item_count} sản phẩm)</Text><Text style={[styles.body, styles.summaryValue, { color: colors.text }]}>{money.format(cart.subtotal)}</Text></View>
          <Text style={[styles.note, { color: colors.muted }]}>Giá và tồn kho được đọc lại từ backend. Voucher, loyalty và tổng thanh toán cần được xác nhận tại bước đặt hàng.</Text>
          <Pressable accessibilityRole="button" onPress={() => router.push('/(tabs)/explore')} style={({ pressed }) => [styles.secondary, { borderColor: colors.border, opacity: pressed ? 0.75 : 1 }]}><Text style={[styles.secondaryText, { color: colors.brand }]}>Tiếp tục mua sắm</Text></Pressable>
        </View>}
      </>}
    />
  </SafeAreaView>;
}

const styles = StyleSheet.create({
  screen: { flex: 1 }, content: { width: '100%', maxWidth: 760, alignSelf: 'center', padding: spacing.lg, gap: spacing.md, paddingBottom: 36 },
  header: { gap: 5, marginBottom: 4 }, title: { fontSize: 27, fontWeight: '800' }, body: { fontSize: 13, lineHeight: 19 },
  gate: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl, gap: spacing.md }, icon: { width: 54, height: 54, borderRadius: radius.md, alignItems: 'center', justifyContent: 'center' },
  primary: { minHeight: 48, minWidth: 190, alignItems: 'center', justifyContent: 'center', borderRadius: radius.md, paddingHorizontal: spacing.lg }, primaryText: { color: '#FFFFFF', fontSize: 15, fontWeight: '800' },
  status: { alignItems: 'center', gap: 9, paddingVertical: 32 }, empty: { alignItems: 'center', gap: spacing.sm, padding: spacing.xl, borderWidth: 1, borderRadius: radius.lg, marginTop: 12 }, emptyTitle: { fontSize: 18, fontWeight: '800' },
  line: { flexDirection: 'row', alignItems: 'center', gap: 12, borderWidth: 1, borderRadius: radius.md, padding: 12, marginBottom: spacing.sm }, lineCopy: { flex: 1, minWidth: 0, gap: 4 }, brand: { fontSize: 11, fontWeight: '700' }, productName: { fontSize: 14, fontWeight: '800', lineHeight: 19 }, price: { fontSize: 14, fontWeight: '800' },
  controls: { flexDirection: 'row', alignItems: 'center', gap: 9, marginTop: 6 }, stepper: { width: 40, height: 40, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderRadius: radius.sm }, quantity: { minWidth: 24, textAlign: 'center', fontWeight: '800' }, delete: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center', marginLeft: 'auto' },
  summary: { borderWidth: 1, borderRadius: radius.lg, padding: spacing.lg, gap: spacing.md, marginTop: spacing.sm }, summaryRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }, summaryValue: { fontWeight: '800' }, note: { fontSize: 12, lineHeight: 18 },
  secondary: { minHeight: 46, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderRadius: radius.md }, secondaryText: { fontSize: 14, fontWeight: '800' }, retry: { minHeight: 44, justifyContent: 'center', paddingHorizontal: 12 }, error: { fontSize: 13, lineHeight: 19, paddingVertical: 4 },
});
