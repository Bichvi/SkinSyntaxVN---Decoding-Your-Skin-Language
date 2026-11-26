import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View, useColorScheme } from 'react-native';
import Ionicons from '@expo/vector-icons/Ionicons';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useRouter } from 'expo-router';

import { useMobileAuth } from '@/src/auth/MobileAuthProvider';
import { palette, radius, spacing } from '@/src/theme/tokens';

const links = [
  { route: '/profile', icon: 'person-outline' as const, title: 'Hồ sơ cá nhân', detail: 'Thông tin tài khoản SkinSyntax.' },
  { route: '/addresses', icon: 'location-outline' as const, title: 'Địa chỉ giao hàng', detail: 'Địa chỉ được lưu theo tài khoản.' },
  { route: '/skin-profile', icon: 'water-outline' as const, title: 'Hồ sơ da', detail: 'Loại da, vấn đề da và ngân sách.' },
  { route: '/orders', icon: 'receipt-outline' as const, title: 'Đơn hàng', detail: 'Lịch sử đơn hàng của bạn.' },
  { route: '/favorites', icon: 'heart-outline' as const, title: 'Sản phẩm yêu thích', detail: 'Đồng bộ với danh sách trên website.' },
  { route: '/vouchers', icon: 'ticket-outline' as const, title: 'Ví voucher', detail: 'Ưu đãi đã nhận theo tài khoản.' },
  { route: '/loyalty', icon: 'ribbon-outline' as const, title: 'Điểm thành viên', detail: 'Hạng thành viên và điểm hiện có.' },
];

export default function AccountScreen() {
  const colors = palette[useColorScheme() === 'dark' ? 'dark' : 'light'];
  const router = useRouter();
  const { user, loading, error, signOut, refresh } = useMobileAuth();
  const [actionError, setActionError] = useState('');
  const [busy, setBusy] = useState(false);

  const logout = async () => {
    setBusy(true); setActionError('');
    try { await signOut(); }
    catch (cause) { setActionError(cause instanceof Error ? cause.message : 'Chưa thể đăng xuất.'); }
    finally { setBusy(false); }
  };

  return <SafeAreaView edges={['left', 'right']} style={[styles.screen, { backgroundColor: colors.background }]}>
    <ScrollView contentContainerStyle={styles.content}>
      <Text accessibilityRole="header" style={[styles.title, { color: colors.text }]}>Tài khoản</Text>
      {loading ? <View style={styles.center}><ActivityIndicator color={colors.brand} /><Text style={{ color: colors.muted, marginTop: 10 }}>Đang kiểm tra phiên đăng nhập…</Text></View> : user ? <>
        <View style={[styles.identity, { backgroundColor: colors.surfaceTint }]}>
          <View style={[styles.avatar, { backgroundColor: colors.surface }]}><Ionicons name="person-outline" size={25} color={colors.brand} /></View>
          <View style={{ flex: 1, gap: 3 }}>
            <Text style={[styles.name, { color: colors.text }]}>{user.ho_ten || 'Khách hàng SkinSyntax'}</Text>
            <Text style={[styles.body, { color: colors.muted }]}>{user.email}</Text>
            <Text style={[styles.sync, { color: colors.secondary }]}>Đã đăng nhập · dữ liệu từ tài khoản SkinSyntax</Text>
          </View>
        </View>
        {error ? <Text accessibilityLiveRegion="polite" style={[styles.error, { color: colors.warning }]}>{error}</Text> : null}
        {links.map(link => <Pressable key={link.route} accessibilityRole="button" onPress={() => router.push(link.route as never)}
          style={({ pressed }) => [styles.link, { backgroundColor: colors.surface, borderColor: colors.border, opacity: pressed ? 0.78 : 1 }]}>
          <View style={[styles.linkIcon, { backgroundColor: colors.surfaceTint }]}><Ionicons name={link.icon} size={21} color={colors.brand} /></View>
          <View style={{ flex: 1, gap: 3 }}><Text style={[styles.linkTitle, { color: colors.text }]}>{link.title}</Text><Text style={[styles.body, { color: colors.muted }]}>{link.detail}</Text></View>
          <Ionicons name="chevron-forward" size={18} color={colors.muted} />
        </Pressable>)}
        <Pressable accessibilityRole="button" disabled={busy} onPress={logout} style={({ pressed }) => [styles.logout, { borderColor: colors.border, opacity: pressed || busy ? 0.6 : 1 }]}>
          {busy ? <ActivityIndicator color={colors.brand} /> : <Ionicons name="log-out-outline" size={18} color={colors.brand} />}
          <Text style={[styles.logoutText, { color: colors.brand }]}>Đăng xuất</Text>
        </Pressable>
        {!!actionError && <Text accessibilityLiveRegion="polite" style={[styles.error, { color: colors.warning }]}>{actionError}</Text>}
      </> : <View style={[styles.guest, { backgroundColor: colors.surface, borderColor: colors.border }]}>
        <View style={[styles.avatar, { backgroundColor: colors.surfaceTint }]}><Ionicons name="person-outline" size={25} color={colors.brand} /></View>
        <Text style={[styles.guestTitle, { color: colors.text }]}>Đăng nhập để đồng bộ tài khoản</Text>
        <Text style={[styles.body, { color: colors.muted, textAlign: 'center' }]}>Giỏ hàng, yêu thích, hồ sơ da và đơn hàng được tải từ backend theo tài khoản.</Text>
        {!!error && <Text accessibilityLiveRegion="polite" style={[styles.error, { color: colors.warning }]}>{error}</Text>}
        <Pressable accessibilityRole="button" onPress={() => router.push('/sign-in')} style={({ pressed }) => [styles.primary, { backgroundColor: colors.brandStrong, opacity: pressed ? 0.8 : 1 }]}><Text style={styles.primaryText}>Đăng nhập</Text></Pressable>
        <Text style={[styles.body, { color: colors.muted, textAlign: 'center' }]}>Đăng ký tài khoản mới chưa được hỗ trợ trực tiếp trong ứng dụng.</Text>
        <Pressable accessibilityRole="button" onPress={() => void refresh().catch(() => undefined)} style={styles.retry}><Text style={{ color: colors.muted, fontWeight: '700' }}>Kiểm tra lại phiên</Text></Pressable>
      </View>}
    </ScrollView>
  </SafeAreaView>;
}

const styles = StyleSheet.create({
  screen: { flex: 1 }, content: { width: '100%', maxWidth: 760, alignSelf: 'center', padding: spacing.lg, gap: spacing.md, paddingBottom: 36 },
  title: { fontSize: 27, fontWeight: '800' }, center: { alignItems: 'center', paddingVertical: 50 },
  identity: { flexDirection: 'row', alignItems: 'center', gap: 13, borderRadius: radius.lg, padding: spacing.lg, marginBottom: 3 },
  avatar: { width: 50, height: 50, alignItems: 'center', justifyContent: 'center', borderRadius: radius.pill },
  name: { fontSize: 17, fontWeight: '800' }, body: { fontSize: 13, lineHeight: 19 }, sync: { fontSize: 12, lineHeight: 17, fontWeight: '700', marginTop: 2 },
  link: { minHeight: 78, flexDirection: 'row', alignItems: 'center', gap: 12, padding: 13, borderWidth: 1, borderRadius: radius.md },
  linkIcon: { width: 42, height: 42, alignItems: 'center', justifyContent: 'center', borderRadius: radius.md }, linkTitle: { fontSize: 15, fontWeight: '800' },
  logout: { minHeight: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 9, borderWidth: 1, borderRadius: radius.md, marginTop: 3 }, logoutText: { fontSize: 14, fontWeight: '800' },
  guest: { alignItems: 'center', gap: spacing.md, borderWidth: 1, borderRadius: radius.lg, padding: spacing.xl, marginTop: 8 }, guestTitle: { fontSize: 19, lineHeight: 26, fontWeight: '800', textAlign: 'center' },
  primary: { minHeight: 48, width: '100%', justifyContent: 'center', alignItems: 'center', borderRadius: radius.md }, primaryText: { color: '#FFFFFF', fontWeight: '800', fontSize: 15 },
  secondary: { minHeight: 48, width: '100%', justifyContent: 'center', alignItems: 'center', borderWidth: 1, borderRadius: radius.md }, secondaryText: { fontWeight: '800', fontSize: 15 },
  retry: { minHeight: 44, justifyContent: 'center', paddingHorizontal: spacing.sm }, error: { fontSize: 13, lineHeight: 19, textAlign: 'center' },
});
