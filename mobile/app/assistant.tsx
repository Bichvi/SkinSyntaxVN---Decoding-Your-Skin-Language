import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, FlatList, Image, KeyboardAvoidingView, Platform, Pressable, StyleSheet, Text, TextInput, View, useColorScheme } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import Ionicons from '@expo/vector-icons/Ionicons';
import { useLocalSearchParams } from 'expo-router';
import { openBrowserAsync } from 'expo-web-browser';

import { buildApiUrl, routes } from '@/src/config/api';
import { askSyna, type SkinSyntaxProduct, type SynaTurn } from '@/src/services/api';
import { palette, radius, spacing } from '@/src/theme/tokens';
import { ProductCard } from '@/src/components/ProductCard';

type Message = { id: string; role: 'assistant' | 'user'; content: string; products?: SkinSyntaxProduct[]; conflicts?: string[]; pending?: boolean };

const suggestions = ['Routine cho da dầu mụn', 'Retinol dùng thế nào cho an toàn?', 'Gợi ý kem chống nắng'];

export default function AssistantScreen() {
  const params = useLocalSearchParams<{ prompt?: string }>();
  const colors = palette[useColorScheme() === 'dark' ? 'dark' : 'light'];
  const list = useRef<FlatList<Message>>(null);
  const inFlight = useRef(false);
  const requestedPrompt = typeof params.prompt === 'string' ? params.prompt : '';
  const [messages, setMessages] = useState<Message[]>([{ id: 'welcome', role: 'assistant', content: 'Xin chào, mình là SYNA. Bạn có thể hỏi về thành phần, cách kết hợp hoạt chất, sản phẩm trong catalog hoặc nhờ gợi ý routine.' }]);
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const [conversationId, setConversationId] = useState<string | null>(null);
  const [error, setError] = useState('');

  const send = useCallback(async (raw: string) => {
    const content = raw.trim();
    if (!content || inFlight.current) return;
    inFlight.current = true;
    const userTurn: Message = { id: `${Date.now()}-user`, role: 'user', content };
    const pending: Message = { id: `${Date.now()}-pending`, role: 'assistant', content: '', pending: true };
    const before = messages;
    setMessages([...before, userTurn, pending]); setDraft(''); setError(''); setSending(true);
    requestAnimationFrame(() => list.current?.scrollToEnd({ animated: true }));

    const history: SynaTurn[] = before.filter(item => !item.pending).slice(-6).map(item => ({ role: item.role, content: item.content }));
    try {
      const reply = await askSyna(content, history, conversationId);
      setConversationId(reply.conversationId);
      setMessages(current => [...current.filter(item => !item.pending), {
        id: `${Date.now()}-assistant`, role: 'assistant', content: reply.answer,
        products: reply.products, conflicts: reply.conflicts,
      }]);
    } catch (cause) {
      const message = cause instanceof Error ? cause.message : 'SYNA chưa thể trả lời lúc này.';
      setError(message);
      setMessages(current => [...current.filter(item => !item.pending), { id: `${Date.now()}-failed`, role: 'assistant', content: 'Mình chưa nhận được câu trả lời. Bạn có thể thử gửi lại.' }]);
    } finally {
      inFlight.current = false;
      setSending(false);
      requestAnimationFrame(() => list.current?.scrollToEnd({ animated: true }));
    }
  }, [conversationId, messages]);

  useEffect(() => {
    if (requestedPrompt) void send(requestedPrompt);
  // The query is a one-shot prompt when this route is opened from Routine.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [requestedPrompt]);

  const reset = () => {
    setConversationId(null); setDraft(''); setError('');
    setMessages([{ id: `${Date.now()}-welcome`, role: 'assistant', content: 'Mình có thể giúp bạn đọc bảng thành phần, tìm sản phẩm và sắp xếp routine. Bạn muốn bắt đầu từ đâu?' }]);
  };

  const openProduct = async (product: SkinSyntaxProduct) => {
    try { await openBrowserAsync(buildApiUrl(routes.productDetail, { id: product.id })); }
    catch { setError('Không mở được trang sản phẩm.'); }
  };
  const muted = { color: colors.muted };

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: colors.background }]} edges={['top', 'left', 'right', 'bottom']}>
      <View style={[styles.header, { borderBottomColor: colors.border }]}>
        <View style={styles.identity}>
          <Image source={require('../assets/images/syna.png')} style={styles.avatar} />
          <View><Text accessibilityRole="header" style={[styles.title, { color: colors.text }]}>SYNA</Text><Text style={[styles.online, { color: colors.secondary }]}>Tư vấn từ SkinSyntax</Text></View>
        </View>
        <Pressable accessibilityRole="button" accessibilityLabel="Bắt đầu hội thoại mới" disabled={sending} onPress={reset} style={styles.newChat}><Ionicons name="create-outline" size={22} color={colors.brand} /></Pressable>
      </View>
      <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined} keyboardVerticalOffset={Platform.OS === 'ios' ? 4 : 0}>
        <FlatList ref={list} data={messages} keyExtractor={item => item.id} keyboardShouldPersistTaps="handled" contentContainerStyle={styles.messageList}
          onContentSizeChange={() => list.current?.scrollToEnd({ animated: false })}
          renderItem={({ item }) => {
            const mine = item.role === 'user';
            return <View style={[styles.messageGroup, mine && styles.messageGroupMine]}>
              {!mine && <Text style={[styles.sender, muted]}>SYNA</Text>}
              <View style={[styles.bubble, { backgroundColor: mine ? colors.brandStrong : colors.surface, borderColor: colors.border }, mine && styles.userBubble]}>
                {item.pending ? <View style={styles.typing}><ActivityIndicator size="small" color={colors.brand} /><Text style={muted}>SYNA đang suy nghĩ…</Text></View>
                  : <Text selectable style={[styles.messageText, { color: mine ? '#FFFFFF' : colors.text }]}>{item.content}</Text>}
                {!!item.conflicts?.length && <View style={[styles.conflict, { backgroundColor: colors.warningSurface }]}><Ionicons name="warning-outline" color={colors.warning} size={17} /><Text style={{ color: colors.warning, flex: 1, lineHeight: 19 }}>{item.conflicts.join('\n')}</Text></View>}
              </View>
              {!!item.products?.length && <View style={styles.productList}>{item.products.map(product => <ProductCard key={product.id} product={product} colors={colors} onPress={() => { void openProduct(product); }} />)}</View>}
            </View>;
          }}
          ListHeaderComponent={messages.length === 1 ? <View style={styles.suggestions}><Text style={[styles.helper, muted]}>Gợi ý câu hỏi</Text>{suggestions.map(question => <Pressable key={question} accessibilityRole="button" disabled={sending} onPress={() => { void send(question); }} style={[styles.suggestion, { borderColor: colors.border, backgroundColor: colors.surface }]}><Text style={{ color: colors.text }}>{question}</Text><Ionicons name="arrow-up-outline" size={17} color={colors.brand} /></Pressable>)}</View> : null}
        />
        {!!error && <Text accessibilityLiveRegion="polite" style={[styles.error, { color: colors.warning }]}>{error}</Text>}
        <View style={[styles.composer, { backgroundColor: colors.surface, borderColor: colors.border }]}>
          <TextInput accessibilityLabel="Tin nhắn cho SYNA" multiline maxLength={800} value={draft} onChangeText={setDraft}
            placeholder="Hỏi SYNA về làn da của bạn…" placeholderTextColor={colors.muted} style={[styles.input, { color: colors.text }]}
            editable={!sending} onSubmitEditing={() => { void send(draft); }} blurOnSubmit />
          <Pressable accessibilityRole="button" accessibilityLabel="Gửi tin nhắn" disabled={!draft.trim() || sending} onPress={() => { void send(draft); }} style={[styles.send, { backgroundColor: draft.trim() && !sending ? colors.brandStrong : colors.border }]}>
            <Ionicons name="arrow-up" color={draft.trim() && !sending ? '#FFFFFF' : colors.muted} size={21} />
          </Pressable>
        </View>
        <Text style={[styles.disclaimer, muted]}>Thông tin tham khảo; thử sản phẩm mới trên vùng da nhỏ trước khi dùng.</Text>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 }, flex: { flex: 1 }, header: { minHeight: 66, paddingHorizontal: 18, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', borderBottomWidth: StyleSheet.hairlineWidth }, identity: { flexDirection: 'row', alignItems: 'center', gap: 10 }, avatar: { width: 42, height: 42, borderRadius: 21 }, title: { fontSize: 18, fontWeight: '800' }, online: { fontSize: 12, fontWeight: '600' }, newChat: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  messageList: { padding: spacing.lg, paddingBottom: 22, gap: 16 }, messageGroup: { alignSelf: 'stretch', maxWidth: 680 }, messageGroupMine: { alignSelf: 'flex-end', maxWidth: '88%' }, sender: { fontSize: 11, fontWeight: '700', marginBottom: 4, marginLeft: 3 }, bubble: { borderWidth: 1, borderRadius: 17, borderTopLeftRadius: 5, padding: 13 }, userBubble: { borderTopLeftRadius: 17, borderTopRightRadius: 5 }, messageText: { fontSize: 15, lineHeight: 22 }, typing: { flexDirection: 'row', alignItems: 'center', gap: 9, minHeight: 25 }, conflict: { marginTop: 10, padding: 10, borderRadius: 10, flexDirection: 'row', gap: 7 }, productList: { marginTop: 8 },
  suggestions: { gap: 8, marginBottom: 16 }, helper: { fontSize: 12, fontWeight: '700', marginBottom: 2 }, suggestion: { minHeight: 44, paddingHorizontal: 12, borderWidth: 1, borderRadius: radius.md, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
  composer: { marginHorizontal: 14, padding: 7, minHeight: 54, borderWidth: 1, borderRadius: 22, flexDirection: 'row', alignItems: 'center', gap: 7 }, input: { flex: 1, maxHeight: 112, minHeight: 40, paddingHorizontal: 10, paddingVertical: 8, fontSize: 15 }, send: { width: 40, height: 40, borderRadius: 20, alignItems: 'center', justifyContent: 'center' }, error: { marginHorizontal: 18, marginBottom: 6, fontSize: 12 }, disclaimer: { textAlign: 'center', fontSize: 10, paddingHorizontal: 16, paddingVertical: 8 },
});
