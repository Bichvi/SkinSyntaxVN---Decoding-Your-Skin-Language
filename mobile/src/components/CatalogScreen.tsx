import { useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Image, Modal, Pressable, RefreshControl, ScrollView, StyleSheet, Text, TextInput, View, useColorScheme } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import Ionicons from '@expo/vector-icons/Ionicons';
import { useRouter } from 'expo-router';

import { apiMode } from '@/src/config/api';
import { useMobileAuth } from '@/src/auth/MobileAuthProvider';
import { searchProducts, type SkinSyntaxProduct } from '@/src/services/api';
import { createIdempotencyKey, mobileApiRequest } from '@/src/services/mobileApi';
import { palette, radius, spacing } from '@/src/theme/tokens';
import { ProductCard } from './ProductCard';
import { ProductImage } from './ProductImage';

const categories = ['Serum', 'Kem chống nắng', 'Sữa rửa mặt', 'Kem dưỡng'];
const money = new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' });

export function CatalogScreen({ home = false }: { home?: boolean }) {
  const router = useRouter();
  const { user } = useMobileAuth();
  const colors = palette[useColorScheme() === 'dark' ? 'dark' : 'light'];
  const [query, setQuery] = useState('');
  const [products, setProducts] = useState<SkinSyntaxProduct[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [reload, setReload] = useState(0);
  const [brand, setBrand] = useState('');
  const [selected, setSelected] = useState<SkinSyntaxProduct | null>(null);
  const [detail, setDetail] = useState<Record<string, unknown> | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailBusy, setDetailBusy] = useState(false);
  const [favorite, setFavorite] = useState(false);
  const [detailError, setDetailError] = useState('');
  const trimmed = query.trim();
  const tooShort = !home && trimmed.length === 1;

  useEffect(() => {
    setBrand(''); setError(''); setProducts([]); setLoading(!tooShort);
    if (tooShort) return;
    const controller = new AbortController();
    const timer = setTimeout(async () => {
      try {
        const result = await searchProducts(trimmed, controller.signal);
        if (!controller.signal.aborted) setProducts([...new Map(result.map(item => [item.id, item])).values()]);
      } catch (cause) {
        if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : 'Không tải được sản phẩm.');
      } finally { if (!controller.signal.aborted) setLoading(false); }
    }, home ? 0 : 280);
    return () => { clearTimeout(timer); controller.abort(); };
  }, [home, reload, tooShort, trimmed]);

  const brands = [...new Set(products.map(item => item.brand).filter(Boolean))];
  const shown = brand ? products.filter(item => item.brand === brand) : products;
  const text = { color: colors.text }; const muted = { color: colors.muted }; const accent = { color: colors.brand };

  useEffect(() => {
    if (!selected) { setDetail(null); setFavorite(false); return; }
    let active = true;
    setDetailLoading(true); setDetailError(''); setDetail(null);
    void mobileApiRequest<{ product: Record<string, unknown> }>('catalog.product', { params: { id: selected.id } })
      .then(response => { if (active) setDetail(response.product); })
      .catch(cause => { if (active) setDetailError(cause instanceof Error ? cause.message : 'Không tải được chi tiết sản phẩm.'); })
      .finally(() => { if (active) setDetailLoading(false); });
    if (user) {
      void mobileApiRequest<{ product_ids: string[] }>('favorites.list')
        .then(response => { if (active) setFavorite(response.product_ids.includes(selected.id)); })
        .catch(() => { if (active) setFavorite(false); });
    }
    return () => { active = false; };
  }, [selected, user]);

  const addToCart = async () => {
    if (!selected) return;
    if (!user) { router.push('/sign-in'); return; }
    setDetailBusy(true); setDetailError('');
    try {
      await mobileApiRequest('cart.add', { method: 'POST', body: { product_id: selected.id, quantity: 1 }, idempotencyKey: createIdempotencyKey() });
      setDetailError('Đã thêm sản phẩm vào giỏ hàng.');
    } catch (cause) { setDetailError(cause instanceof Error ? cause.message : 'Chưa thể thêm vào giỏ hàng.'); }
    finally { setDetailBusy(false); }
  };

  const toggleFavorite = async () => {
    if (!selected) return;
    if (!user) { router.push('/sign-in'); return; }
    setDetailBusy(true); setDetailError('');
    try {
      const next = !favorite;
      await mobileApiRequest('favorites.set', { method: 'PUT', body: { product_id: selected.id, favorite: next } });
      setFavorite(next);
      setDetailError(next ? 'Đã lưu vào sản phẩm yêu thích.' : 'Đã bỏ sản phẩm khỏi yêu thích.');
    } catch (cause) { setDetailError(cause instanceof Error ? cause.message : 'Chưa thể cập nhật yêu thích.'); }
    finally { setDetailBusy(false); }
  };

  const detailText = (key: string, fallback = '') => typeof detail?.[key] === 'string' ? String(detail[key]) : fallback;
  const ingredientText = Array.isArray(detail?.ingredients)
    ? detail.ingredients.map(value => typeof value === 'string' ? value : JSON.stringify(value)).join(', ')
    : detailText('ingredients');

  return (
    <SafeAreaView edges={['left', 'right']} style={[styles.screen, { backgroundColor: colors.background }]}>
      <FlatList data={shown} numColumns={2} columnWrapperStyle={styles.productGrid} keyExtractor={item => item.id} keyboardShouldPersistTaps="handled" keyboardDismissMode="on-drag"
        refreshControl={<RefreshControl refreshing={loading} onRefresh={() => setReload(n => n + 1)} tintColor={colors.brand} />}
        contentContainerStyle={styles.content}
        ListHeaderComponent={<View style={styles.header}>
          {home ? <>
            <View style={styles.brandRow}>
              <Image source={require('../../assets/images/syna.png')} style={styles.mascot} resizeMode="contain" />
              <View style={{ flex: 1 }}><Text style={[styles.eyebrow, { color: colors.secondary }]}>DECODING YOUR SKIN LANGUAGE</Text><Text accessibilityRole="header" style={[styles.brandTitle, text]}>SkinSyntaxVN</Text></View>
              <Pressable accessibilityRole="button" accessibilityLabel="Mở tài khoản" onPress={() => router.push('/account')} style={[styles.iconButton, { backgroundColor: colors.surface, borderColor: colors.border }]}><Ionicons name="person-outline" color={colors.brand} size={21} /></Pressable>
            </View>
            <View style={[styles.hero, { backgroundColor: colors.surfaceTint }]}>
              <Text style={[styles.eyebrow, { color: colors.secondary }]}>CHĂM DA CÓ CƠ SỞ</Text>
              <Text style={[styles.heroTitle, text]}>Tìm sản phẩm phù hợp với làn da của bạn.</Text>
              <Text style={[styles.heroBody, muted]}>Khám phá catalog thật hoặc hỏi SYNA về thành phần và routine.</Text>
              <View style={styles.actions}>
                <Pressable accessibilityRole="button" onPress={() => router.push('/explore')} style={[styles.primaryButton, { backgroundColor: colors.brandStrong }]}><Ionicons name="search-outline" color="#FFFFFF" size={17} /><Text style={styles.primaryText}>Tìm sản phẩm</Text></Pressable>
                <Pressable accessibilityRole="button" onPress={() => router.push('/assistant')} style={[styles.secondaryButton, { backgroundColor: colors.surface, borderColor: colors.border }]}><Ionicons name="sparkles-outline" color={colors.brand} size={18} /><Text style={[styles.secondaryText, accent]}>Hỏi SYNA</Text></Pressable>
              </View>
            </View>
          </> : <>
            <Text accessibilityRole="header" style={[styles.pageTitle, text]}>Khám phá</Text>
            <View style={[styles.search, { backgroundColor: colors.surface, borderColor: colors.border }]}><Ionicons name="search-outline" color={colors.brand} size={20} /><TextInput accessibilityLabel="Tìm sản phẩm hoặc thương hiệu" style={[styles.input, text]} placeholder="Sản phẩm, thương hiệu…" placeholderTextColor={colors.muted} autoCorrect={false} value={query} returnKeyType="search" onChangeText={setQuery} clearButtonMode="while-editing" /></View>
            <Text style={muted}>Tìm theo tên sản phẩm hoặc thương hiệu.</Text>
          </>}
          {apiMode === 'development' && <Text style={muted}>Máy chủ phát triển</Text>}
          <Text accessibilityRole="header" style={[styles.sectionTitle, text]}>{home ? 'Khám phá danh mục' : trimmed ? 'Kết quả tìm kiếm' : 'Đang được quan tâm'}</Text>
          <ScrollView horizontal keyboardShouldPersistTaps="handled" showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
            {categories.map(category => { const active = trimmed === category; return <Pressable key={category} accessibilityRole="button" accessibilityState={{ selected: active }} onPress={() => setQuery(category)} style={[styles.chip, { borderColor: active ? colors.brand : colors.border, backgroundColor: active ? colors.surfaceTint : colors.surface }]}><Text style={[styles.chipText, active ? accent : text]}>{category}</Text></Pressable>; })}
          </ScrollView>
          {brands.length > 1 && !home && <ScrollView horizontal keyboardShouldPersistTaps="handled" showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>{['', ...brands].map(value => <Pressable key={value || 'all'} accessibilityRole="button" accessibilityState={{ selected: brand === value }} onPress={() => setBrand(value)} style={[styles.chip, { borderColor: brand === value ? colors.brand : colors.border }]}><Text style={brand === value ? accent : muted}>{value || 'Tất cả hãng'}</Text></Pressable>)}</ScrollView>}
          {!home && !!trimmed && <Text style={muted}>{shown.length} sản phẩm</Text>}
        </View>}
        ListEmptyComponent={loading ? <ActivityIndicator accessibilityLabel="Đang tải sản phẩm" color={colors.brand} style={styles.status} /> : <View style={styles.status}><Ionicons name={error ? 'cloud-offline-outline' : 'search-outline'} size={27} color={colors.muted} /><Text accessibilityLiveRegion="polite" style={[styles.statusText, muted]}>{error || (tooShort ? 'Nhập ít nhất 2 ký tự.' : 'Chưa tìm thấy sản phẩm phù hợp.')}</Text>{!!error && <Pressable accessibilityRole="button" style={styles.retry} onPress={() => setReload(n => n + 1)}><Text style={[styles.chipText, accent]}>Thử lại</Text></Pressable>}</View>}
        renderItem={({ item }) => <ProductCard layout="grid" product={item} colors={colors} onPress={() => { setSelected(item); setDetailError(''); }} />}
      />
      <Modal visible={!!selected} onRequestClose={() => setSelected(null)} presentationStyle="pageSheet" animationType="slide">
        {selected && <SafeAreaView style={[styles.screen, { backgroundColor: colors.background }]}><ScrollView contentContainerStyle={styles.detail}>
          <Pressable accessibilityRole="button" accessibilityLabel="Đóng chi tiết" style={styles.close} onPress={() => setSelected(null)}><Ionicons name="close-outline" color={colors.text} size={25} /></Pressable>
          <ProductImage uris={selected.imageUrls} colors={colors} size="large" />
          {!!selected.brand && <Text style={muted}>{selected.brand}</Text>}
          <Text accessibilityRole="header" style={[styles.detailTitle, text]}>{selected.name}</Text>
          {!!selected.category && <Text style={muted}>{selected.category}</Text>}
          <Text style={[styles.detailPrice, accent]}>{typeof detail?.price === 'number' ? money.format(detail.price) : selected.price === null ? 'Giá chưa cập nhật' : money.format(selected.price)}</Text>
          {detailLoading && <ActivityIndicator accessibilityLabel="Đang tải chi tiết" color={colors.brand} />}
          {!!detailText('description') && <Text style={[styles.detailBody, muted]}>{detailText('description')}</Text>}
          {!!ingredientText && <View style={[styles.detailInfo, { backgroundColor: colors.surface, borderColor: colors.border }]}><Text style={[styles.detailLabel, text]}>Thành phần</Text><Text style={[styles.detailBody, muted]}>{ingredientText}</Text></View>}
          {!!detailText('usage') && <View style={[styles.detailInfo, { backgroundColor: colors.surface, borderColor: colors.border }]}><Text style={[styles.detailLabel, text]}>Hướng dẫn sử dụng</Text><Text style={[styles.detailBody, muted]}>{detailText('usage')}</Text></View>}
          {typeof detail?.stock === 'number' && <Text style={muted}>{detail.stock > 0 ? `Còn ${detail.stock} sản phẩm` : 'Tạm hết hàng'}</Text>}
          <View style={styles.detailActions}>
            <Pressable accessibilityRole="button" disabled={detailBusy || detail?.available === false} style={[styles.primaryButton, styles.buyButton, { flex: 1, backgroundColor: colors.brandStrong, opacity: detailBusy || detail?.available === false ? 0.65 : 1 }]} onPress={() => void addToCart()}>
              {detailBusy ? <ActivityIndicator color="#FFFFFF" /> : <><Ionicons name="bag-add-outline" color="#FFFFFF" size={18} /><Text style={styles.primaryText}>Thêm vào giỏ</Text></>}
            </Pressable>
            <Pressable accessibilityRole="button" accessibilityLabel={favorite ? 'Bỏ yêu thích' : 'Thêm yêu thích'} disabled={detailBusy} onPress={() => void toggleFavorite()} style={[styles.favoriteButton, { borderColor: colors.border }]}>
              <Ionicons name={favorite ? 'heart' : 'heart-outline'} color={colors.brand} size={21} />
            </Pressable>
          </View>
          {!!detailError && <Text accessibilityLiveRegion="polite" style={[styles.detailMessage, { color: colors.secondary }]}>{detailError}</Text>}
        </ScrollView></SafeAreaView>}
      </Modal>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1 }, content: { paddingHorizontal: spacing.lg, paddingTop: spacing.md, paddingBottom: 36, width: '100%', maxWidth: 900, alignSelf: 'center' },
  header: { gap: spacing.md, marginBottom: spacing.lg }, brandRow: { flexDirection: 'row', alignItems: 'center', gap: 10 }, mascot: { width: 48, height: 48 },
  eyebrow: { fontSize: 9, fontWeight: '800', letterSpacing: 1 }, brandTitle: { fontSize: 20, fontWeight: '800' }, iconButton: { width: 44, height: 44, borderWidth: 1, borderRadius: radius.pill, alignItems: 'center', justifyContent: 'center' },
  hero: { padding: spacing.xl, borderRadius: radius.lg, gap: 9 }, heroTitle: { fontSize: 25, fontWeight: '800', lineHeight: 31 }, heroBody: { fontSize: 14, lineHeight: 21 }, actions: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, marginTop: 4 },
  primaryButton: { minHeight: 46, paddingHorizontal: 15, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, borderRadius: radius.md }, primaryText: { color: '#FFFFFF', fontSize: 14, fontWeight: '800' }, secondaryButton: { minHeight: 46, paddingHorizontal: 14, flexDirection: 'row', alignItems: 'center', gap: 7, borderWidth: 1, borderRadius: radius.md }, secondaryText: { fontSize: 14, fontWeight: '800' },
  pageTitle: { fontSize: 28, fontWeight: '800' }, search: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingHorizontal: 12, borderWidth: 1, borderRadius: radius.md }, input: { flex: 1, minHeight: 48, fontSize: 16 }, sectionTitle: { fontSize: 19, lineHeight: 25, fontWeight: '800' }, chips: { gap: spacing.sm }, chip: { paddingHorizontal: 13, minHeight: 42, justifyContent: 'center', borderWidth: 1, borderRadius: radius.pill }, chipText: { fontSize: 13, fontWeight: '700' },
  productGrid: { gap: spacing.sm, marginBottom: spacing.sm, justifyContent: 'space-between' }, status: { alignItems: 'center', gap: 9, paddingVertical: 28 }, statusText: { textAlign: 'center', fontSize: 14, lineHeight: 21 }, retry: { minHeight: 44, justifyContent: 'center', paddingHorizontal: 12 }, detail: { padding: spacing.lg, paddingBottom: 34, width: '100%', maxWidth: 740, alignSelf: 'center', gap: 12 }, close: { alignSelf: 'flex-end', width: 44, height: 44, alignItems: 'center', justifyContent: 'center' }, detailTitle: { fontSize: 22, fontWeight: '800', lineHeight: 29 }, detailPrice: { fontSize: 19, fontWeight: '800' }, detailBody: { fontSize: 13, lineHeight: 19 }, detailLabel: { fontSize: 14, fontWeight: '800' }, detailInfo: { gap: 6, padding: 13, borderWidth: 1, borderRadius: radius.md }, detailActions: { flexDirection: 'row', gap: 9, marginTop: 6 }, buyButton: { marginTop: 0 }, favoriteButton: { width: 48, minHeight: 46, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderRadius: radius.md }, detailMessage: { fontSize: 13, lineHeight: 19 },
});
