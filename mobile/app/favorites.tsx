import { useCallback, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, StyleSheet, Text, View, useColorScheme } from 'react-native';
import { useFocusEffect, useRouter } from 'expo-router';
import Ionicons from '@expo/vector-icons/Ionicons';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useMobileAuth } from '@/src/auth/MobileAuthProvider';
import { mobileApiRequest } from '@/src/services/mobileApi';
import { ProductImage } from '@/src/components/ProductImage';
import { palette, radius, spacing } from '@/src/theme/tokens';

type Product = { id: string; name: string; brand: string; category: string; price: number; image_url: string; available: boolean };
const money = new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' });

export default function FavoritesScreen() {
  const colors = palette[useColorScheme() === 'dark' ? 'dark' : 'light'];
  const { user } = useMobileAuth();
  const router = useRouter();
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');

  const load = useCallback(async (refresh = false) => {
    if (!user) return;
    if (refresh) setRefreshing(true);
    else setLoading(true);
    setError('');
    try {
      const response = await mobileApiRequest<{ items: Product[] }>('favorites.list');
      setProducts(response.items);
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Không tải được yêu thích.'); }
    finally { setLoading(false); setRefreshing(false); }
  }, [user]);
  useFocusEffect(useCallback(() => { void load(); }, [load]));

  const remove = async (product: Product) => {
    setError('');
    try {
      await mobileApiRequest('favorites.set', { method: 'PUT', body: { product_id: product.id, favorite: false } });
      setProducts(current => current.filter(item => item.id !== product.id));
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Chưa thể cập nhật yêu thích.'); }
  };

  if (!user) return <SafeAreaView style={[styles.screen, { backgroundColor: colors.background }]}><View style={styles.center}>
    <Ionicons name="heart-outline" size={34} color={colors.brand} /><Text style={[styles.title, { color: colors.text }]}>Yêu thích theo tài khoản</Text>
    <Text style={[styles.body, { color: colors.muted, textAlign: 'center' }]}>Đăng nhập để xem sản phẩm đã lưu trên website.</Text>
    <Pressable accessibilityRole="button" onPress={() => router.push('/sign-in')} style={[styles.button, { backgroundColor: colors.brandStrong }]}><Text style={styles.buttonText}>Đăng nhập</Text></Pressable>
  </View></SafeAreaView>;

  return <SafeAreaView edges={['left', 'right']} style={[styles.screen, { backgroundColor: colors.background }]}>
    <FlatList data={products} keyExtractor={item => item.id} contentContainerStyle={styles.content} refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void load(true)} tintColor={colors.brand} />}
      ListHeaderComponent={<Text accessibilityRole="header" style={[styles.title, { color: colors.text }]}>Sản phẩm yêu thích</Text>}
      ListEmptyComponent={loading ? <View style={styles.center}><ActivityIndicator color={colors.brand} /><Text style={{ color: colors.muted }}>Đang tải…</Text></View> : <Text style={[styles.body, { color: colors.muted, textAlign: 'center', padding: 24 }]}>Chưa có sản phẩm yêu thích.</Text>}
      renderItem={({ item }) => <View style={[styles.row, { backgroundColor: colors.surface, borderColor: colors.border }]}>
        <ProductImage uris={[item.image_url]} colors={colors} size="medium" />
        <View style={styles.copy}><Text numberOfLines={1} style={[styles.brand, { color: colors.muted }]}>{item.brand}</Text><Text numberOfLines={2} style={[styles.name, { color: colors.text }]}>{item.name}</Text><Text style={[styles.price, { color: colors.brand }]}>{money.format(item.price)}</Text></View>
        <Pressable accessibilityRole="button" accessibilityLabel={`Bỏ yêu thích ${item.name}`} onPress={() => void remove(item)} style={styles.remove}><Ionicons name="heart" size={21} color={colors.brand} /></Pressable>
      </View>}
      ListFooterComponent={<>{!!error && <Text accessibilityLiveRegion="polite" style={{ color: colors.warning }}>{error}</Text>}</>}
    />
  </SafeAreaView>;
}

const styles = StyleSheet.create({
  screen: { flex: 1 }, content: { width: '100%', maxWidth: 760, alignSelf: 'center', padding: spacing.lg, paddingBottom: 36 }, title: { fontSize: 26, fontWeight: '800', marginBottom: spacing.md }, body: { fontSize: 13, lineHeight: 19 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl, gap: spacing.md }, button: { minHeight: 46, minWidth: 180, alignItems: 'center', justifyContent: 'center', borderRadius: radius.md, paddingHorizontal: spacing.lg }, buttonText: { color: '#FFFFFF', fontWeight: '800' },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, borderWidth: 1, borderRadius: radius.md, padding: 12, marginBottom: spacing.sm }, copy: { flex: 1, minWidth: 0, gap: 3 }, brand: { fontSize: 11, fontWeight: '700' }, name: { fontSize: 14, fontWeight: '800' }, price: { fontSize: 13, fontWeight: '800' }, remove: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
});
