import { useEffect, useState } from 'react';
import { Image, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import Ionicons from '@expo/vector-icons/Ionicons';

import type { AppColors } from '@/src/theme/tokens';

type ProductImageProps = {
  uris: string[];
  colors: AppColors;
  size?: 'small' | 'medium' | 'large';
  style?: StyleProp<ViewStyle>;
};

export function ProductImage({ uris, colors, size = 'medium', style }: ProductImageProps) {
  const sourceKey = uris.join('|');
  const [index, setIndex] = useState(0);

  useEffect(() => setIndex(0), [sourceKey]);

  const dimensions = size === 'large' ? styles.large : size === 'small' ? styles.small : styles.medium;
  const uri = uris[index];

  return (
    <View style={[styles.frame, dimensions, { backgroundColor: colors.surfaceTint }, style]}>
      {uri ? (
        <Image key={uri} source={{ uri }} style={styles.photo} resizeMode="contain"
          onError={() => setIndex(current => current + 1)} />
      ) : (
        <Ionicons name="image-outline" size={size === 'large' ? 38 : 26} color={colors.muted} />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  frame: { alignItems: 'center', justifyContent: 'center', overflow: 'hidden', borderRadius: 12 },
  small: { width: 74, height: 74 },
  medium: { width: 92, height: 92 },
  large: { width: '100%', height: 260 },
  photo: { width: '100%', height: '100%' },
});
