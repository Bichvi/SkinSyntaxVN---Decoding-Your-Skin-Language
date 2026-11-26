import { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View, useColorScheme } from 'react-native';
import Ionicons from '@expo/vector-icons/Ionicons';
import { useFocusEffect, useRouter } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useMobileAuth } from '@/src/auth/MobileAuthProvider';
import { mobileApiRequest } from '@/src/services/mobileApi';
import { palette, radius, spacing } from '@/src/theme/tokens';

export default function RoutineScreen() {
  const router = useRouter();
  const colors = palette[useColorScheme() === 'dark' ? 'dark' : 'light'];
  const { user } = useMobileAuth();
  const [skinContext, setSkinContext] = useState('');
  const [loading, setLoading] = useState(false);

  const loadSkinContext = useCallback(async () => {
    if (!user) { setSkinContext(''); return; }
    setLoading(true);
    try {
      const response = await mobileApiRequest<{ profiles: Record<string, unknown>[] }>('account.skin-profile');
      const profile = response.profiles?.[0];
      if (!profile) { setSkinContext(''); return; }
      const concerns = Array.isArray(profile.van_de_da) ? profile.van_de_da.map(String).join(', ') : String(profile.van_de_da ?? '');
      setSkinContext([profile.loai_da ? `Loại da: ${String(profile.loai_da)}` : '', concerns ? `Mối quan tâm: ${concerns}` : '', profile.muc_tieu_cham_soc ? `Mục tiêu: ${String(profile.muc_tieu_cham_soc)}` : ''].filter(Boolean).join('. '));
    } catch { setSkinContext(''); }
    finally { setLoading(false); }
  }, [user]);
  useFocusEffect(useCallback(() => { void loadSkinContext(); }, [loadSkinContext]));

  const createRoutine = () => {
    const profilePrompt = skinContext ? ` Hồ sơ da của tôi: ${skinContext}.` : '';
    router.push({ pathname: '/assistant', params: { prompt: `Gợi ý routine chăm sóc da buổi sáng và buổi tối. Dùng sản phẩm đang có trong catalog, giải thích thứ tự và lưu ý phối hợp hoạt chất.${profilePrompt}` } });
  };

  return <SafeAreaView edges={['left', 'right']} style={[styles.screen, { backgroundColor: colors.background }]}>
    <ScrollView contentContainerStyle={styles.content}>
      <Text accessibilityRole="header" style={[styles.title, { color: colors.text }]}>Routine chăm da</Text>
      <Text style={[styles.lead, { color: colors.muted }]}>Bắt đầu từ tình trạng da và sản phẩm có thật trong catalog SkinSyntax.</Text>
      <View style={[styles.feature, { backgroundColor: colors.surfaceTint }]}>
        <View style={[styles.featureIcon, { backgroundColor: colors.surface }]}><Ionicons name="sparkles-outline" size={24} color={colors.brand} /></View>
        <Text style={[styles.featureTitle, { color: colors.text }]}>Tạo gợi ý cùng SYNA</Text>
        <Text style={[styles.featureBody, { color: colors.muted }]}>{user ? 'Dùng catalog thật và hồ sơ da trong tài khoản để tạo gợi ý theo buổi sáng, buổi tối.' : 'SYNA dùng catalog thật để gợi ý các bước chăm da. Đăng nhập để đưa hồ sơ da của bạn vào câu hỏi.'}</Text>
        {loading && <ActivityIndicator accessibilityLabel="Đang tải hồ sơ da" color={colors.brand} />}
        <Pressable accessibilityRole="button" onPress={createRoutine} style={[styles.button, { backgroundColor: colors.brandStrong }]}>
          <Text style={styles.buttonText}>Tạo routine với SYNA</Text><Ionicons name="arrow-forward" color="#FFFFFF" size={18} />
        </Pressable>
      </View>
      <Text style={[styles.section, { color: colors.text }]}>Hồ sơ và khảo sát da</Text>
      <Pressable accessibilityRole="button" onPress={() => router.push(user ? '/skin-profile' : '/sign-in')} style={({ pressed }) => [styles.destination, { backgroundColor: colors.surface, borderColor: colors.border, opacity: pressed ? 0.78 : 1 }]}>
        <View style={[styles.destinationIcon, { backgroundColor: colors.surfaceTint }]}><Ionicons name="clipboard-outline" size={21} color={colors.brand} /></View>
        <View style={{ flex: 1, gap: 3 }}><Text style={[styles.destinationTitle, { color: colors.text }]}>Hồ sơ da & khảo sát nhanh</Text><Text style={[styles.destinationBody, { color: colors.muted }]}>{user ? 'Cập nhật thông tin và xem lịch sử đã lưu.' : 'Đăng nhập để lưu kết quả khảo sát vào tài khoản.'}</Text></View>
        <Ionicons name="chevron-forward" size={19} color={colors.muted} />
      </Pressable>
      <Text style={[styles.note, { color: colors.muted }]}>Routine là gợi ý tham khảo. Khi mới dùng hoạt chất, hãy bắt đầu từ tần suất thấp và theo dõi phản ứng của da.</Text>
    </ScrollView>
  </SafeAreaView>;
}

const styles = StyleSheet.create({
  screen: { flex: 1 }, content: { width: '100%', maxWidth: 760, alignSelf: 'center', padding: spacing.lg, gap: spacing.md }, title: { fontSize: 27, fontWeight: '800' }, lead: { fontSize: 14, lineHeight: 21 },
  feature: { borderRadius: radius.lg, padding: spacing.xl, gap: 11, marginTop: 4 }, featureIcon: { width: 48, height: 48, alignItems: 'center', justifyContent: 'center', borderRadius: 16 }, featureTitle: { fontSize: 20, fontWeight: '800' }, featureBody: { fontSize: 14, lineHeight: 21 }, button: { minHeight: 47, borderRadius: radius.md, paddingHorizontal: 16, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 9, marginTop: 4 }, buttonText: { color: '#FFFFFF', fontSize: 14, fontWeight: '800' }, section: { fontSize: 17, fontWeight: '800', marginTop: 6 }, note: { fontSize: 12, lineHeight: 18, marginTop: 5 }, destination: { minHeight: 74, flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 13, paddingVertical: 10, borderWidth: 1, borderRadius: radius.md }, destinationIcon: { width: 42, height: 42, alignItems: 'center', justifyContent: 'center', borderRadius: radius.md }, destinationTitle: { fontSize: 14, fontWeight: '800' }, destinationBody: { fontSize: 12, lineHeight: 17 },
});
