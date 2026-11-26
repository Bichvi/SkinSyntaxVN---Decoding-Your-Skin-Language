import { useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View, useColorScheme } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useRouter } from 'expo-router';
import Ionicons from '@expo/vector-icons/Ionicons';

import { useMobileAuth } from '@/src/auth/MobileAuthProvider';
import { palette, radius, spacing } from '@/src/theme/tokens';

export default function SignInScreen() {
  const colors = palette[useColorScheme() === 'dark' ? 'dark' : 'light'];
  const router = useRouter();
  const { signIn } = useMobileAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const submit = async () => {
    setBusy(true); setError('');
    try {
      await signIn(email, password);
      router.replace('/(tabs)/account');
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Không thể đăng nhập lúc này.');
    } finally { setBusy(false); }
  };

  return <SafeAreaView edges={['left', 'right']} style={[styles.screen, { backgroundColor: colors.background }]}>
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={styles.content}>
        <View style={[styles.mark, { backgroundColor: colors.surfaceTint }]}><Ionicons name="person-circle-outline" size={30} color={colors.brand} /></View>
        <Text accessibilityRole="header" style={[styles.title, { color: colors.text }]}>Đăng nhập SkinSyntax</Text>
        <Text style={[styles.description, { color: colors.muted }]}>Dùng cùng tài khoản với website để tải giỏ hàng, hồ sơ và dữ liệu đã lưu.</Text>
        <View style={styles.field}>
          <Text style={[styles.label, { color: colors.text }]}>Email</Text>
          <TextInput accessibilityLabel="Email" autoComplete="email" textContentType="emailAddress" keyboardType="email-address" autoCapitalize="none" autoCorrect={false} value={email} onChangeText={setEmail} returnKeyType="next" placeholder="ten@email.com" placeholderTextColor={colors.muted} style={[styles.input, { color: colors.text, backgroundColor: colors.surface, borderColor: colors.border }]} />
        </View>
        <View style={styles.field}>
          <Text style={[styles.label, { color: colors.text }]}>Mật khẩu</Text>
          <TextInput accessibilityLabel="Mật khẩu" autoComplete="current-password" textContentType="password" secureTextEntry value={password} onChangeText={setPassword} onSubmitEditing={() => void submit()} returnKeyType="go" placeholder="Nhập mật khẩu" placeholderTextColor={colors.muted} style={[styles.input, { color: colors.text, backgroundColor: colors.surface, borderColor: colors.border }]} />
        </View>
        {!!error && <Text accessibilityLiveRegion="polite" style={[styles.error, { color: colors.warning }]}>{error}</Text>}
        <Pressable accessibilityRole="button" accessibilityState={{ disabled: busy }} disabled={busy || !email.trim() || !password} onPress={() => void submit()} style={({ pressed }) => [styles.button, { backgroundColor: colors.brandStrong, opacity: busy || pressed ? 0.72 : 1 }]}>
          {busy ? <ActivityIndicator color="#FFFFFF" /> : <Text style={styles.buttonText}>Đăng nhập</Text>}
        </Pressable>
        <Text style={[styles.privacy, { color: colors.muted }]}>Đăng ký tài khoản mới hiện chưa hỗ trợ trực tiếp trong ứng dụng.</Text>
        <Text style={[styles.privacy, { color: colors.muted }]}>Mật khẩu chỉ được gửi để xác thực và không được lưu trong ứng dụng. Phiên iOS/Android được lưu trong SecureStore.</Text>
      </ScrollView>
    </KeyboardAvoidingView>
  </SafeAreaView>;
}

const styles = StyleSheet.create({
  screen: { flex: 1 }, content: { width: '100%', maxWidth: 560, alignSelf: 'center', padding: spacing.xl, paddingTop: 50, gap: spacing.md },
  mark: { width: 58, height: 58, alignItems: 'center', justifyContent: 'center', borderRadius: radius.lg }, title: { fontSize: 26, lineHeight: 33, fontWeight: '800' }, description: { fontSize: 14, lineHeight: 21, marginBottom: spacing.sm },
  field: { gap: 7 }, label: { fontSize: 14, fontWeight: '700' }, input: { minHeight: 50, borderWidth: 1, borderRadius: radius.md, paddingHorizontal: 14, fontSize: 16 },
  button: { minHeight: 50, alignItems: 'center', justifyContent: 'center', borderRadius: radius.md, marginTop: spacing.sm }, buttonText: { color: '#FFFFFF', fontSize: 15, fontWeight: '800' },
  error: { fontSize: 13, lineHeight: 19 }, register: { minHeight: 44, alignItems: 'center', justifyContent: 'center' }, registerText: { fontSize: 14, fontWeight: '800' }, privacy: { fontSize: 12, lineHeight: 18, marginTop: spacing.sm },
});
