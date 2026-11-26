import { useCallback, useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View, useColorScheme } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useFocusEffect } from 'expo-router';

import { useMobileAuth } from '@/src/auth/MobileAuthProvider';
import { mobileApiRequest } from '@/src/services/mobileApi';
import { palette, radius, spacing, type AppColors } from '@/src/theme/tokens';

type Profile = { ho_ten: string; email: string; so_dien_thoai: string; gioi_tinh: string; nam_sinh: number | string | null; dia_chi: string };
const emptyProfile: Profile = { ho_ten: '', email: '', so_dien_thoai: '', gioi_tinh: '', nam_sinh: '', dia_chi: '' };

export default function ProfileScreen() {
  const colors = palette[useColorScheme() === 'dark' ? 'dark' : 'light'];
  const { refresh } = useMobileAuth();
  const [profile, setProfile] = useState<Profile>(emptyProfile);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = useCallback(async () => {
    setLoading(true); setError('');
    try {
      const response = await mobileApiRequest<{ profile: Profile }>('account.profile');
      setProfile({ ...emptyProfile, ...response.profile });
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Không tải được hồ sơ.'); }
    finally { setLoading(false); }
  }, []);
  useFocusEffect(useCallback(() => { void load(); }, [load]));

  const save = async () => {
    setSaving(true); setError(''); setNotice('');
    try {
      const response = await mobileApiRequest<{ profile: Profile }>('account.profile', {
        method: 'PATCH', body: {
          ho_ten: profile.ho_ten.trim(), so_dien_thoai: profile.so_dien_thoai.trim(),
          gioi_tinh: profile.gioi_tinh.trim(), nam_sinh: profile.nam_sinh || null, dia_chi: profile.dia_chi.trim(),
        },
      });
      setProfile({ ...emptyProfile, ...response.profile });
      await refresh().catch(() => null);
      setNotice('Đã lưu hồ sơ vào tài khoản SkinSyntax.');
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Không thể lưu hồ sơ.'); }
    finally { setSaving(false); }
  };

  return <SafeAreaView edges={['left', 'right']} style={[styles.screen, { backgroundColor: colors.background }]}>
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={styles.content}>
        <Text accessibilityRole="header" style={[styles.title, { color: colors.text }]}>Hồ sơ cá nhân</Text>
        <Text style={[styles.caption, { color: colors.muted }]}>Thông tin này được lưu theo tài khoản và dùng chung trên website.</Text>
        {loading ? <View style={styles.center}><ActivityIndicator color={colors.brand} /><Text style={{ color: colors.muted }}>Đang tải hồ sơ…</Text></View> : <>
          <Field label="Họ và tên" value={profile.ho_ten} onChange={value => setProfile(current => ({ ...current, ho_ten: value }))} colors={colors} />
          <Field label="Email" value={profile.email} editable={false} colors={colors} />
          <Field label="Số điện thoại" value={profile.so_dien_thoai} keyboardType="phone-pad" onChange={value => setProfile(current => ({ ...current, so_dien_thoai: value }))} colors={colors} />
          <Field label="Giới tính" value={profile.gioi_tinh} onChange={value => setProfile(current => ({ ...current, gioi_tinh: value }))} colors={colors} />
          <Field label="Năm sinh" value={String(profile.nam_sinh ?? '')} keyboardType="number-pad" onChange={value => setProfile(current => ({ ...current, nam_sinh: value }))} colors={colors} />
          <Field label="Địa chỉ mặc định" value={profile.dia_chi} onChange={value => setProfile(current => ({ ...current, dia_chi: value }))} colors={colors} multiline />
          {!!error && <Text accessibilityLiveRegion="polite" style={[styles.message, { color: colors.warning }]}>{error}</Text>}
          {!!notice && <Text accessibilityLiveRegion="polite" style={[styles.message, { color: colors.secondary }]}>{notice}</Text>}
          <Pressable accessibilityRole="button" disabled={saving} onPress={() => void save()} style={({ pressed }) => [styles.button, { backgroundColor: colors.brandStrong, opacity: saving || pressed ? 0.72 : 1 }]}>
            {saving ? <ActivityIndicator color="#FFFFFF" /> : <Text style={styles.buttonText}>Lưu thay đổi</Text>}
          </Pressable>
        </>}
      </ScrollView>
    </KeyboardAvoidingView>
  </SafeAreaView>;
}

function Field({ label, value, onChange, colors, editable = true, keyboardType = 'default', multiline = false }: {
  label: string; value: string; onChange?: (value: string) => void; colors: AppColors; editable?: boolean; keyboardType?: 'default' | 'phone-pad' | 'number-pad'; multiline?: boolean;
}) {
  return <View style={styles.field}><Text style={[styles.label, { color: colors.text }]}>{label}</Text><TextInput accessibilityLabel={label} value={value} onChangeText={onChange} editable={editable} keyboardType={keyboardType} multiline={multiline} textAlignVertical={multiline ? 'top' : 'center'} style={[styles.input, { color: colors.text, backgroundColor: editable ? colors.surface : colors.surfaceTint, borderColor: colors.border }, multiline && styles.multiline]} /></View>;
}

const styles = StyleSheet.create({ screen: { flex: 1 }, content: { width: '100%', maxWidth: 620, alignSelf: 'center', padding: spacing.lg, paddingBottom: 36, gap: spacing.md }, title: { fontSize: 27, fontWeight: '800' }, caption: { fontSize: 13, lineHeight: 19, marginBottom: spacing.sm }, field: { gap: 7 }, label: { fontSize: 14, fontWeight: '700' }, input: { minHeight: 49, borderWidth: 1, borderRadius: radius.md, paddingHorizontal: 13, fontSize: 16 }, multiline: { minHeight: 92, paddingTop: 12 }, center: { alignItems: 'center', paddingVertical: 50, gap: 10 }, button: { minHeight: 49, alignItems: 'center', justifyContent: 'center', borderRadius: radius.md, marginTop: 5 }, buttonText: { color: '#FFFFFF', fontSize: 15, fontWeight: '800' }, message: { fontSize: 13, lineHeight: 19 },
});
