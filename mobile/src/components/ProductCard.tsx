import { Pressable, StyleSheet, Text, View } from 'react-native';
import Ionicons from '@expo/vector-icons/Ionicons';

import type { SkinSyntaxProduct } from '@/src/services/api';
import type { AppColors } from '@/src/theme/tokens';
import { ProductImage } from './ProductImage';

const currency = new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' });

type ProductCardProps = {
  product: SkinSyntaxProduct;
  colors: AppColors;
  onPress: () => void;
  layout?: 'row' | 'grid';
};

export function ProductCard({ product, colors, onPress, layout = 'row' }: ProductCardProps) {
  const price = product.price === null ? 'Giá chưa cập nhật' : currency.format(product.price);

  return (
    <Pressable accessibilityRole="button" accessibilityLabel={`Xem ${product.name}`}
      onPress={onPress} style={({ pressed }) => [
        layout === 'grid' ? [styles.gridCard, { flexBasis: '48%' }] : styles.rowCard,
        { backgroundColor: colors.surface, borderColor: colors.border, opacity: pressed ? 0.82 : 1 },
      ]}>
      <ProductImage uris={product.imageUrls} colors={colors} size={layout === 'grid' ? 'large' : 'medium'}
        style={layout === 'grid' ? styles.gridImage : undefined} />
      <View style={styles.copy}>
        {!!product.brand && <Text numberOfLines={1} style={[styles.brand, { color: colors.muted }]}>{product.brand}</Text>}
        <Text numberOfLines={layout === 'grid' ? 3 : 2} style={[styles.name, { color: colors.text }]}>{product.name}</Text>
        <Text style={[styles.price, { color: colors.brand }]}>{price}</Text>
        {product.available === false && (
          <Text style={[styles.stock, { color: colors.warning }]}>
            <Ionicons name="alert-circle-outline" size={13} /> Tạm hết hàng
          </Text>
        )}
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  rowCard: { flexDirection: 'row', alignItems: 'center', gap: 14, paddingVertical: 13, borderBottomWidth: StyleSheet.hairlineWidth },
  gridCard: { minWidth: 0, padding: 10, borderWidth: 1, borderRadius: 16, gap: 8 },
  gridImage: { width: '100%', height: 150 },
  copy: { flex: 1, minWidth: 0, gap: 5 },
  brand: { fontSize: 12, fontWeight: '600' },
  name: { fontSize: 14, fontWeight: '700', lineHeight: 19 },
  price: { fontSize: 14, fontWeight: '800' },
  stock: { fontSize: 12, fontWeight: '600' },
});
