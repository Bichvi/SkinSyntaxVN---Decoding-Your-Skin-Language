import { useCallback, useState } from 'react';
import { ActivityIndicator, Modal, Pressable, ScrollView, StyleSheet, Text, TextInput, View, useColorScheme } from 'react-native';
import Ionicons from '@expo/vector-icons/Ionicons';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useFocusEffect } from 'expo-router';

import { mobileApiRequest } from '@/src/services/mobileApi';
import { palette, radius, spacing, type AppColors } from '@/src/theme/tokens';

type Address = { id: string; receiver_name: string; phone: string; province: string; ward: string; street: string; address_type: string; is_default: boolean };
type AddressForm = Omit<Address, 'id' | 'is_default'>;
const blank: AddressForm = { receiver_name: '', phone: '', province: '', ward: '', street: '', address_type: 'home' };

export default function AddressesScreen() {
  const colors = palette[useColorScheme() === 'dark' ? 'dark' : 'light'];
  const [addresses, setAddresses] = useState<Address[]>([]);
  const [form, setForm] = useState<AddressForm>(blank);
  const [editingId, setEditingId] = useState('');
  const [visible, setVisible] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true); setError('');
    try { const response = await mobileApiRequest<{ addresses: Address[] }>('account.addresses'); setAddresses(response.addresses); }
    catch (cause) { setError(cause instanceof Error ? cause.message : 'Không tải được địa chỉ.'); }
    finally { setLoading(false); }
  }, []);
  useFocusEffect(useCallback(() => { void load(); }, [load]));

  const add = async () => {
    setSaving(true); setError('');
    try {
      const response = await mobileApiRequest<{ addresses: Address[] }>('account.addresses', {
        method: editingId ? 'PATCH' : 'POST',
        body: editingId
          ? { address_id: editingId, ...form }
          : { ...form, is_default: addresses.length === 0 },
      });
      setAddresses(response.addresses); setVisible(false); setForm(blank);
      setEditingId('');
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Không thể lưu địa chỉ.'); }
    finally { setSaving(false); }
  };

  const update = async (address: Address, patch: Record<string, unknown>) => {
    setError('');
    try {
      const response = await mobileApiRequest<{ addresses: Address[] }>('account.addresses', { method: 'PATCH', body: { address_id: address.id, ...patch } });
      setAddresses(response.addresses);
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Không thể cập nhật địa chỉ.'); }
  };

  const remove = async (address: Address) => {
    setError('');
    try {
      const response = await mobileApiRequest<{ addresses: Address[] }>('account.addresses', { method: 'DELETE', body: { address_id: address.id } });
      setAddresses(response.addresses);
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Không thể xóa địa chỉ.'); }
  };

  return <SafeAreaView edges={['left', 'right']} style={[styles.screen, { backgroundColor: colors.background }]}>
    <ScrollView contentContainerStyle={styles.content}>
      <View style={styles.header}><View style={{ flex: 1 }}><Text accessibilityRole="header" style={[styles.title, { color: colors.text }]}>Địa chỉ giao hàng</Text><Text style={[styles.caption, { color: colors.muted }]}>Địa chỉ được đồng bộ theo tài khoản SkinSyntax.</Text></View><Pressable accessibilityRole="button" accessibilityLabel="Thêm địa chỉ" onPress={() => { setError(''); setEditingId(''); setForm(blank); setVisible(true); }} style={[styles.addButton, { backgroundColor: colors.brandStrong }]}><Ionicons name="add" size={22} color="#FFFFFF" /></Pressable></View>
      {!!error && <Text accessibilityLiveRegion="polite" style={[styles.message, { color: colors.warning }]}>{error}</Text>}
      {loading ? <View style={styles.center}><ActivityIndicator color={colors.brand} /><Text style={{ color: colors.muted }}>Đang tải địa chỉ…</Text></View> : addresses.length === 0 ? <View style={[styles.empty, { backgroundColor: colors.surface, borderColor: colors.border }]}><Ionicons name="location-outline" size={30} color={colors.brand} /><Text style={[styles.emptyTitle, { color: colors.text }]}>Chưa có địa chỉ đã lưu</Text><Text style={[styles.caption, { color: colors.muted, textAlign: 'center' }]}>Thêm địa chỉ để dùng nhanh trong các đơn hàng tiếp theo.</Text></View> : addresses.map(address => <View key={address.id} style={[styles.card, { backgroundColor: colors.surface, borderColor: colors.border }]}>
        <View style={styles.cardHeading}><Text style={[styles.name, { color: colors.text }]}>{address.receiver_name}</Text>{address.is_default && <Text style={[styles.badge, { color: colors.secondary, backgroundColor: colors.surfaceTint }]}>Mặc định</Text>}</View>
        <Text style={[styles.details, { color: colors.muted }]}>{address.phone}</Text><Text style={[styles.details, { color: colors.muted }]}>{[address.street, address.ward, address.province].filter(Boolean).join(', ')}</Text>
        <View style={styles.actions}>{!address.is_default && <Pressable accessibilityRole="button" onPress={() => void update(address, { is_default: true })} style={[styles.action, { borderColor: colors.border }]}><Text style={[styles.actionText, { color: colors.brand }]}>Đặt mặc định</Text></Pressable>}<Pressable accessibilityRole="button" accessibilityLabel={`Sửa địa chỉ ${address.receiver_name}`} onPress={() => { setError(''); setEditingId(address.id); setForm({ receiver_name: address.receiver_name, phone: address.phone, province: address.province, ward: address.ward, street: address.street, address_type: address.address_type }); setVisible(true); }} style={[styles.delete, { borderColor: colors.border }]}><Ionicons name="create-outline" size={17} color={colors.brand} /></Pressable><Pressable accessibilityRole="button" accessibilityLabel={`Xóa địa chỉ ${address.receiver_name}`} onPress={() => void remove(address)} style={[styles.delete, { borderColor: colors.border }]}><Ionicons name="trash-outline" size={17} color={colors.warning} /></Pressable></View>
      </View>)}
    </ScrollView>
    <Modal visible={visible} animationType="slide" presentationStyle="pageSheet" onRequestClose={() => setVisible(false)}>
      <SafeAreaView style={[styles.screen, { backgroundColor: colors.background }]}><ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={styles.modalContent}>
        <View style={styles.header}><Text accessibilityRole="header" style={[styles.title, { color: colors.text }]}>{editingId ? 'Sửa địa chỉ' : 'Thêm địa chỉ'}</Text><Pressable accessibilityRole="button" accessibilityLabel="Đóng" onPress={() => { setVisible(false); setEditingId(''); }} style={styles.close}><Ionicons name="close" size={22} color={colors.text} /></Pressable></View>
        <AddressField label="Người nhận" value={form.receiver_name} field="receiver_name" update={setForm} colors={colors} />
        <AddressField label="Số điện thoại" value={form.phone} field="phone" update={setForm} colors={colors} keyboardType="phone-pad" />
        <AddressField label="Tỉnh / thành phố" value={form.province} field="province" update={setForm} colors={colors} />
        <AddressField label="Phường / xã" value={form.ward} field="ward" update={setForm} colors={colors} />
        <AddressField label="Số nhà, đường" value={form.street} field="street" update={setForm} colors={colors} />
        <View style={styles.typeRow}>{(['home', 'company'] as const).map(type => <Pressable key={type} accessibilityRole="button" accessibilityState={{ selected: form.address_type === type }} onPress={() => setForm(current => ({ ...current, address_type: type }))} style={[styles.typeButton, { borderColor: form.address_type === type ? colors.brand : colors.border, backgroundColor: form.address_type === type ? colors.surfaceTint : colors.surface }]}><Text style={{ color: colors.text, fontWeight: '700' }}>{type === 'home' ? 'Nhà riêng' : 'Công ty'}</Text></Pressable>)}</View>
        {!!error && <Text accessibilityLiveRegion="polite" style={[styles.message, { color: colors.warning }]}>{error}</Text>}
        <Pressable accessibilityRole="button" disabled={saving} onPress={() => void add()} style={[styles.save, { backgroundColor: colors.brandStrong, opacity: saving ? 0.7 : 1 }]}>{saving ? <ActivityIndicator color="#FFFFFF" /> : <Text style={styles.saveText}>Lưu địa chỉ</Text>}</Pressable>
      </ScrollView></SafeAreaView>
    </Modal>
  </SafeAreaView>;
}

function AddressField({ label, value, field, update, colors, keyboardType = 'default' }: { label: string; value: string; field: keyof AddressForm; update: React.Dispatch<React.SetStateAction<AddressForm>>; colors: AppColors; keyboardType?: 'default' | 'phone-pad' }) {
  return <View style={styles.field}><Text style={[styles.label, { color: colors.text }]}>{label}</Text><TextInput accessibilityLabel={label} value={value} onChangeText={text => update(current => ({ ...current, [field]: text }))} keyboardType={keyboardType} style={[styles.input, { color: colors.text, backgroundColor: colors.surface, borderColor: colors.border }]} /></View>;
}

const styles = StyleSheet.create({ screen: { flex: 1 }, content: { width: '100%', maxWidth: 740, alignSelf: 'center', padding: spacing.lg, paddingBottom: 36, gap: spacing.md }, modalContent: { padding: spacing.lg, paddingBottom: 34, gap: spacing.md }, header: { flexDirection: 'row', alignItems: 'center', gap: 12 }, title: { fontSize: 26, fontWeight: '800' }, caption: { fontSize: 13, lineHeight: 19, marginTop: 4 }, addButton: { width: 45, height: 45, alignItems: 'center', justifyContent: 'center', borderRadius: radius.pill }, center: { alignItems: 'center', paddingVertical: 48, gap: 10 }, empty: { alignItems: 'center', padding: spacing.xl, gap: spacing.sm, borderWidth: 1, borderRadius: radius.lg }, emptyTitle: { fontSize: 17, fontWeight: '800' }, card: { gap: 6, padding: spacing.md, borderWidth: 1, borderRadius: radius.md }, cardHeading: { flexDirection: 'row', alignItems: 'center', gap: 8, flexWrap: 'wrap' }, name: { flex: 1, fontSize: 15, fontWeight: '800' }, badge: { overflow: 'hidden', borderRadius: radius.pill, paddingHorizontal: 9, paddingVertical: 4, fontSize: 11, fontWeight: '800' }, details: { fontSize: 13, lineHeight: 19 }, actions: { flexDirection: 'row', justifyContent: 'flex-end', gap: 8, marginTop: 5 }, action: { minHeight: 40, justifyContent: 'center', borderWidth: 1, borderRadius: radius.sm, paddingHorizontal: 12 }, actionText: { fontSize: 12, fontWeight: '800' }, delete: { width: 42, height: 42, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderRadius: radius.sm }, field: { gap: 7 }, label: { fontSize: 14, fontWeight: '700' }, input: { minHeight: 48, borderWidth: 1, borderRadius: radius.md, paddingHorizontal: 13, fontSize: 16 }, typeRow: { flexDirection: 'row', gap: 10 }, typeButton: { flex: 1, minHeight: 45, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderRadius: radius.md }, close: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' }, save: { minHeight: 48, alignItems: 'center', justifyContent: 'center', borderRadius: radius.md, marginTop: 4 }, saveText: { color: '#FFFFFF', fontWeight: '800' }, message: { fontSize: 13, lineHeight: 19 },
});
