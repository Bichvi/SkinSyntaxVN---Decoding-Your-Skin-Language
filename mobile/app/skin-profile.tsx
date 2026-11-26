import { useCallback, useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, TextInput, View, useColorScheme } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useFocusEffect } from 'expo-router';

import { mobileApiRequest, createIdempotencyKey } from '@/src/services/mobileApi';
import { palette, radius, spacing, type AppColors } from '@/src/theme/tokens';

type SkinProfile = { profile_id?: string; display_name?: string; loai_da?: string; van_de_da?: string[] | string; muc_do_nhay_cam?: string; muc_tieu_cham_soc?: string; ngan_sach?: number };
type SkinState = { loai_da: string; van_de_da: string[]; muc_do_nhay_cam: string; muc_tieu_cham_soc: string; ngan_sach: string };
const skinTypes = ['Da thường', 'Da khô', 'Da dầu', 'Da hỗn hợp', 'Da nhạy cảm'];
const issueOptions = ['Mụn', 'Thâm', 'Đỏ rát', 'Khô căng', 'Lỗ chân lông', 'Nếp nhăn', 'Không đều màu'];
const empty: SkinState = { loai_da: '', van_de_da: [], muc_do_nhay_cam: '', muc_tieu_cham_soc: '', ngan_sach: '' };

export default function SkinProfileScreen() {
  const colors = palette[useColorScheme() === 'dark' ? 'dark' : 'light'];
  const [state, setState] = useState<SkinState>(empty);
  const [history, setHistory] = useState<Record<string, unknown>[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [surveyBusy, setSurveyBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const issueText = useMemo(() => state.van_de_da.join(', '), [state.van_de_da]);

  const load = useCallback(async () => {
    setLoading(true); setError('');
    try {
      const response = await mobileApiRequest<{ profiles: SkinProfile[]; history: Record<string, unknown>[] }>('account.skin-profile');
      const profile = response.profiles?.[0];
      const issues = Array.isArray(profile?.van_de_da) ? profile.van_de_da.map(String) : typeof profile?.van_de_da === 'string' ? profile.van_de_da.split(/[,|]/).map(value => value.trim()).filter(Boolean) : [];
      setState({
        loai_da: String(profile?.loai_da ?? ''), van_de_da: issues,
        muc_do_nhay_cam: String(profile?.muc_do_nhay_cam ?? ''),
        muc_tieu_cham_soc: String(profile?.muc_tieu_cham_soc ?? ''),
        ngan_sach: profile?.ngan_sach == null ? '' : String(profile.ngan_sach),
      });
      setHistory(Array.isArray(response.history) ? response.history : []);
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Không tải được hồ sơ da.'); }
    finally { setLoading(false); }
  }, []);
  useFocusEffect(useCallback(() => { void load(); }, [load]));

  const saveProfile = async () => {
    setSaving(true); setError(''); setNotice('');
    try {
      await mobileApiRequest('account.skin-profile', { method: 'PATCH', body: { loai_da: state.loai_da, van_de_da: state.van_de_da, ngan_sach: state.ngan_sach || null } });
      setNotice('Đã lưu hồ sơ da vào tài khoản.');
      await load();
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Không thể lưu hồ sơ da.'); }
    finally { setSaving(false); }
  };

  const submitSurvey = async () => {
    setSurveyBusy(true); setError(''); setNotice('');
    try {
      await mobileApiRequest('account.skin-profile', { method: 'POST', body: {
        muc_do_nhay_cam: state.muc_do_nhay_cam, van_de_da: state.van_de_da,
        muc_tieu_cham_soc: state.muc_tieu_cham_soc, ngan_sach: Number(state.ngan_sach) || 0,
        request_id: createIdempotencyKey(),
      } });
      setNotice('Đã lưu kết quả khảo sát da.');
      await load();
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Không thể lưu kết quả khảo sát.'); }
    finally { setSurveyBusy(false); }
  };

  const toggleIssue = (issue: string) => setState(current => ({ ...current, van_de_da: current.van_de_da.includes(issue) ? current.van_de_da.filter(item => item !== issue) : [...current.van_de_da, issue] }));

  return <SafeAreaView edges={['left', 'right']} style={[styles.screen, { backgroundColor: colors.background }]}>
    <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={styles.content}>
      <Text accessibilityRole="header" style={[styles.title, { color: colors.text }]}>Hồ sơ da & khảo sát</Text>
      <Text style={[styles.caption, { color: colors.muted }]}>Hồ sơ da được lưu theo tài khoản. Mỗi khảo sát tạo một bản ghi lịch sử trên backend.</Text>
      {loading ? <View style={styles.center}><ActivityIndicator color={colors.brand} /><Text style={{ color: colors.muted }}>Đang tải dữ liệu da…</Text></View> : <>
        <Text style={[styles.section, { color: colors.text }]}>Loại da</Text>
        <View style={styles.chips}>{skinTypes.map(type => <Choice key={type} label={type} selected={state.loai_da === type} onPress={() => setState(current => ({ ...current, loai_da: type }))} colors={colors} />)}</View>
        <Text style={[styles.section, { color: colors.text }]}>Vấn đề da hiện tại</Text>
        <View style={styles.chips}>{issueOptions.map(issue => <Choice key={issue} label={issue} selected={state.van_de_da.includes(issue)} onPress={() => toggleIssue(issue)} colors={colors} />)}</View>
        <Text style={[styles.section, { color: colors.text }]}>Mức độ nhạy cảm</Text>
        <View style={styles.chips}>{['Thấp', 'Trung bình', 'Cao'].map(value => <Choice key={value} label={value} selected={state.muc_do_nhay_cam === value} onPress={() => setState(current => ({ ...current, muc_do_nhay_cam: value }))} colors={colors} />)}</View>
        <View style={styles.field}><Text style={[styles.label, { color: colors.text }]}>Mục tiêu chăm sóc</Text><TextInput accessibilityLabel="Mục tiêu chăm sóc" value={state.muc_tieu_cham_soc} onChangeText={value => setState(current => ({ ...current, muc_tieu_cham_soc: value }))} placeholder="Ví dụ: giảm mụn và phục hồi hàng rào da" placeholderTextColor={colors.muted} style={[styles.input, { color: colors.text, backgroundColor: colors.surface, borderColor: colors.border }]} /></View>
        <View style={styles.field}><Text style={[styles.label, { color: colors.text }]}>Ngân sách dự kiến (₫)</Text><TextInput accessibilityLabel="Ngân sách dự kiến" value={state.ngan_sach} onChangeText={value => setState(current => ({ ...current, ngan_sach: value.replace(/[^\d]/g, '') }))} keyboardType="number-pad" style={[styles.input, { color: colors.text, backgroundColor: colors.surface, borderColor: colors.border }]} /></View>
        {!!issueText && <Text style={[styles.caption, { color: colors.muted }]}>Đã chọn: {issueText}</Text>}
        {!!error && <Text accessibilityLiveRegion="polite" style={[styles.message, { color: colors.warning }]}>{error}</Text>}
        {!!notice && <Text accessibilityLiveRegion="polite" style={[styles.message, { color: colors.secondary }]}>{notice}</Text>}
        <View style={styles.buttons}>
          <Pressable accessibilityRole="button" disabled={saving} onPress={() => void saveProfile()} style={[styles.button, { backgroundColor: colors.brandStrong, opacity: saving ? 0.7 : 1 }]}>{saving ? <ActivityIndicator color="#FFFFFF" /> : <Text style={styles.buttonText}>Lưu hồ sơ da</Text>}</Pressable>
          <Pressable accessibilityRole="button" disabled={surveyBusy} onPress={() => void submitSurvey()} style={[styles.outline, { borderColor: colors.border, opacity: surveyBusy ? 0.7 : 1 }]}>{surveyBusy ? <ActivityIndicator color={colors.brand} /> : <Text style={[styles.outlineText, { color: colors.brand }]}>Gửi khảo sát nhanh</Text>}</Pressable>
        </View>
        <Text style={[styles.section, { color: colors.text, marginTop: spacing.md }]}>Lịch sử khảo sát</Text>
        {history.length === 0 ? <Text style={[styles.caption, { color: colors.muted }]}>Chưa có kết quả khảo sát được lưu.</Text> : history.slice(0, 8).map((entry, index) => <View key={String(entry.request_id ?? entry.created_at ?? index)} style={[styles.history, { backgroundColor: colors.surface, borderColor: colors.border }]}>
          <Text style={[styles.historyTitle, { color: colors.text }]}>{String(entry.muc_do_nhay_cam ?? 'Khảo sát da')}</Text>
          <Text style={[styles.caption, { color: colors.muted }]}>{Array.isArray(entry.van_de_da) ? entry.van_de_da.map(String).join(', ') : String(entry.van_de_da ?? '')}{entry.muc_tieu_cham_soc ? ` · ${String(entry.muc_tieu_cham_soc)}` : ''}</Text>
        </View>)}
      </>}
    </ScrollView>
  </SafeAreaView>;
}

function Choice({ label, selected, onPress, colors }: { label: string; selected: boolean; onPress: () => void; colors: AppColors }) {
  return <Pressable accessibilityRole="button" accessibilityState={{ selected }} onPress={onPress} style={[styles.choice, { borderColor: selected ? colors.brand : colors.border, backgroundColor: selected ? colors.surfaceTint : colors.surface }]}><Text style={[styles.choiceText, { color: selected ? colors.brand : colors.text }]}>{label}</Text></Pressable>;
}

const styles = StyleSheet.create({ screen: { flex: 1 }, content: { width: '100%', maxWidth: 720, alignSelf: 'center', padding: spacing.lg, paddingBottom: 38, gap: spacing.md }, title: { fontSize: 27, fontWeight: '800' }, caption: { fontSize: 13, lineHeight: 19 }, section: { fontSize: 16, fontWeight: '800', marginTop: 3 }, center: { alignItems: 'center', paddingVertical: 48, gap: 10 }, chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 }, choice: { minHeight: 41, justifyContent: 'center', paddingHorizontal: 12, borderWidth: 1, borderRadius: radius.pill }, choiceText: { fontSize: 13, fontWeight: '700' }, field: { gap: 7 }, label: { fontSize: 14, fontWeight: '700' }, input: { minHeight: 49, borderWidth: 1, borderRadius: radius.md, paddingHorizontal: 13, fontSize: 16 }, buttons: { gap: 9, marginTop: 3 }, button: { minHeight: 48, alignItems: 'center', justifyContent: 'center', borderRadius: radius.md }, buttonText: { color: '#FFFFFF', fontWeight: '800' }, outline: { minHeight: 47, alignItems: 'center', justifyContent: 'center', borderRadius: radius.md, borderWidth: 1 }, outlineText: { fontWeight: '800' }, message: { fontSize: 13, lineHeight: 19 }, history: { borderWidth: 1, borderRadius: radius.md, padding: spacing.md }, historyTitle: { fontSize: 14, fontWeight: '800' },
});
