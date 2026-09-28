import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { API_BASE_URL, searchProducts, SkinSyntaxProduct, SmartSearchResponse } from '@/src/services/api';

type ApiState =
  | { status: 'loading'; products: SkinSyntaxProduct[]; response: null; error: '' }
  | { status: 'success'; products: SkinSyntaxProduct[]; response: SmartSearchResponse; error: '' }
  | { status: 'error'; products: SkinSyntaxProduct[]; response: null; error: string };

function formatCurrency(value: number | null): string {
  if (value === null) {
    return 'Chưa có giá';
  }

  return new Intl.NumberFormat('vi-VN', {
    style: 'currency',
    currency: 'VND',
    maximumFractionDigits: 0,
  }).format(value);
}

export default function HomeScreen() {
  const [apiState, setApiState] = useState<ApiState>({
    status: 'loading',
    products: [],
    response: null,
    error: '',
  });

  const loadProducts = useCallback(async () => {
    setApiState({ status: 'loading', products: [], response: null, error: '' });

    try {
      const { response, products } = await searchProducts('serum');
      setApiState({
        status: 'success',
        products: products.slice(0, 4),
        response,
        error: '',
      });
    } catch (error) {
      setApiState({
        status: 'error',
        products: [],
        response: null,
        error: error instanceof Error ? error.message : 'Không thể kết nối SkinSyntax API.',
      });
    }
  }, []);

  useEffect(() => {
    loadProducts();
  }, [loadProducts]);

  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content}>
      <View style={styles.header}>
        <Text style={styles.brand}>SkinSyntaxVN</Text>
        <Text style={styles.title}>Kiểm tra kết nối SkinSyntax API</Text>
        <Text style={styles.subtitle}>
          Expo Go đang gọi dữ liệu thật từ backend PHP trong cùng hệ thống SkinSyntaxVN.
        </Text>
      </View>

      <View style={styles.panel}>
        <Text style={styles.panelLabel}>Backend development</Text>
        <Text style={styles.urlText}>{API_BASE_URL || 'Chưa cấu hình EXPO_PUBLIC_API_BASE_URL'}</Text>

        <View style={styles.statusRow}>
          {apiState.status === 'loading' ? <ActivityIndicator color="#1F6B45" /> : null}
          <Text style={[styles.statusText, apiState.status === 'error' ? styles.errorText : styles.successText]}>
            {apiState.status === 'loading'
              ? 'Loading...'
              : apiState.status === 'success'
                ? 'Kết nối thành công'
                : 'Không thể kết nối'}
          </Text>
        </View>

        {apiState.status === 'error' ? <Text style={styles.errorMessage}>{apiState.error}</Text> : null}

        <Pressable style={styles.button} onPress={loadProducts}>
          <Text style={styles.buttonText}>Thử lại</Text>
        </Pressable>
      </View>

      {apiState.status === 'success' ? (
        <View style={styles.results}>
          <Text style={styles.sectionTitle}>Dữ liệu từ API thật</Text>
          <Text style={styles.metaText}>
            Response type: {apiState.response.type ?? 'unknown'} | Số item nhận được: {apiState.products.length}
          </Text>

          {apiState.products.map((product) => (
            <View key={product.id || product.name} style={styles.productRow}>
              <View style={styles.productBullet} />
              <View style={styles.productInfo}>
                <Text style={styles.productName} numberOfLines={2}>
                  {product.name}
                </Text>
                <Text style={styles.productPrice}>{formatCurrency(product.price)}</Text>
                <Text style={styles.productMeta}>Mã SP: {product.id || 'N/A'}</Text>
              </View>
            </View>
          ))}
        </View>
      ) : null}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: '#F7FAF8',
  },
  content: {
    padding: 20,
    paddingBottom: 36,
  },
  header: {
    paddingTop: 28,
    paddingBottom: 20,
  },
  brand: {
    color: '#1F6B45',
    fontSize: 16,
    fontWeight: '800',
    letterSpacing: 0,
  },
  title: {
    marginTop: 8,
    color: '#10251A',
    fontSize: 28,
    fontWeight: '800',
    lineHeight: 34,
  },
  subtitle: {
    marginTop: 10,
    color: '#56675D',
    fontSize: 15,
    lineHeight: 22,
  },
  panel: {
    borderWidth: 1,
    borderColor: '#D9E7DE',
    borderRadius: 8,
    padding: 16,
    backgroundColor: '#FFFFFF',
  },
  panelLabel: {
    color: '#6B7D72',
    fontSize: 12,
    fontWeight: '700',
    textTransform: 'uppercase',
  },
  urlText: {
    marginTop: 6,
    color: '#10251A',
    fontSize: 14,
    fontWeight: '600',
  },
  statusRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    marginTop: 18,
  },
  statusText: {
    fontSize: 16,
    fontWeight: '800',
  },
  successText: {
    color: '#1F6B45',
  },
  errorText: {
    color: '#B42318',
  },
  errorMessage: {
    marginTop: 10,
    color: '#B42318',
    fontSize: 14,
    lineHeight: 20,
  },
  button: {
    alignSelf: 'flex-start',
    marginTop: 16,
    borderRadius: 8,
    backgroundColor: '#1F6B45',
    paddingHorizontal: 16,
    paddingVertical: 11,
  },
  buttonText: {
    color: '#FFFFFF',
    fontSize: 14,
    fontWeight: '800',
  },
  results: {
    marginTop: 20,
  },
  sectionTitle: {
    color: '#10251A',
    fontSize: 20,
    fontWeight: '800',
  },
  metaText: {
    marginTop: 6,
    color: '#6B7D72',
    fontSize: 13,
  },
  productRow: {
    flexDirection: 'row',
    gap: 12,
    marginTop: 12,
    borderWidth: 1,
    borderColor: '#D9E7DE',
    borderRadius: 8,
    padding: 14,
    backgroundColor: '#FFFFFF',
  },
  productBullet: {
    width: 10,
    height: 10,
    marginTop: 5,
    borderRadius: 5,
    backgroundColor: '#1F6B45',
  },
  productInfo: {
    flex: 1,
  },
  productName: {
    color: '#10251A',
    fontSize: 15,
    fontWeight: '700',
    lineHeight: 21,
  },
  productPrice: {
    marginTop: 6,
    color: '#1F6B45',
    fontSize: 15,
    fontWeight: '800',
  },
  productMeta: {
    marginTop: 4,
    color: '#6B7D72',
    fontSize: 12,
  },
});
