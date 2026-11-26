import { buildApiUrl, routes } from '../../src/config/api';

const headers = { 'Cache-Control': 'no-store' };
const json = (body: unknown, status = 200) => Response.json(body, { status, headers });

export async function POST(request: Request) {
  try {
    const origin = request.headers.get('origin');
    if (origin && new URL(origin).origin !== new URL(request.url).origin) {
      return json({ message: 'Yêu cầu không hợp lệ.' }, 403);
    }
    if (!request.headers.get('content-type')?.toLowerCase().includes('application/json')) {
      return json({ message: 'Content-Type phải là application/json.' }, 415);
    }
    const declaredLength = Number(request.headers.get('content-length') ?? 0);
    if (declaredLength > 16_384) return json({ message: 'Tin nhắn quá dài.' }, 413);
    const reader = request.body?.getReader();
    const chunks: Uint8Array[] = [];
    let byteLength = 0;
    if (reader) {
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        byteLength += value.byteLength;
        if (byteLength > 16_384) {
          await reader.cancel();
          return json({ message: 'Tin nhắn quá dài.' }, 413);
        }
        chunks.push(value);
      }
    }
    const body = new Uint8Array(byteLength);
    let offset = 0;
    for (const chunk of chunks) { body.set(chunk, offset); offset += chunk.byteLength; }
    let input: Record<string, unknown>;
    try { input = JSON.parse(new TextDecoder().decode(body)) as Record<string, unknown>; }
    catch { return json({ message: 'Payload không hợp lệ.' }, 400); }
    const message = typeof input.message === 'string' ? input.message.trim().slice(0, 800) : '';
    if (!message) return json({ message: 'Vui lòng nhập nội dung cần hỏi SYNA.' }, 400);

    const history = Array.isArray(input.history) ? input.history.slice(-6).flatMap(turn => {
      if (!turn || typeof turn !== 'object') return [];
      const item = turn as Record<string, unknown>;
      if (item.role !== 'user' && item.role !== 'assistant') return [];
      return typeof item.content === 'string' && item.content.trim()
        ? [{ role: item.role, content: item.content.trim().slice(0, 1200) }] : [];
    }) : [];
    const conversationId = typeof input.conversation_id === 'string' ? input.conversation_id.trim().slice(0, 120) : '';

    const upstream = await fetch(buildApiUrl(routes.aiChat), {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ message, history, ...(conversationId ? { conversation_id: conversationId } : {}) }),
      credentials: 'omit', redirect: 'error', signal: AbortSignal.timeout(75_000),
    });
    if (!upstream.ok || !upstream.headers.get('content-type')?.includes('application/json')) {
      return json({ message: 'SYNA chưa thể trả lời lúc này.' }, upstream.status === 429 ? 429 : 502);
    }

    const payload = await upstream.json();
    if (payload?.ok !== true || typeof payload.answer !== 'string') {
      return json({ message: typeof payload?.message === 'string' ? payload.message : 'SYNA chưa thể trả lời lúc này.' }, 502);
    }
    const products = Array.isArray(payload.products) ? payload.products.flatMap((item: unknown) => {
      if (!item || typeof item !== 'object') return [];
      const product = item as Record<string, unknown>;
      const id = String(product.id ?? product.ma_san_pham ?? '').trim().slice(0, 80);
      if (!id) return [];
      return [{
        id,
        name: typeof product.name === 'string' ? product.name.slice(0, 300) : 'Sản phẩm SkinSyntax',
        brand: typeof product.brand === 'string' ? product.brand.slice(0, 120) : '',
        category: typeof product.category === 'string' ? product.category.slice(0, 160) : '',
        price: typeof product.price === 'number' && Number.isFinite(product.price) ? product.price : null,
        image_url: typeof product.image_url === 'string' ? product.image_url.slice(0, 2500) : '',
      }];
    }) : [];

    return json({
      ok: true,
      answer: payload.answer.slice(0, 20_000),
      products,
      conflicts: Array.isArray(payload.conflicts) ? payload.conflicts.flatMap((item: unknown) => {
        if (typeof item === 'string') return [item.slice(0, 700)];
        if (!item || typeof item !== 'object') return [];
        const conflict = item as Record<string, unknown>;
        const products = [conflict.product_a, conflict.product_b].filter((name): name is string => typeof name === 'string' && !!name.trim());
        const warning = typeof conflict.warning === 'string' ? conflict.warning.trim() : '';
        const recommendation = typeof conflict.recommendation === 'string' ? conflict.recommendation.trim() : '';
        const message = [products.length ? `${products.join(' và ')}:` : '', warning, recommendation].filter(Boolean).join(' ');
        return message ? [message.slice(0, 700)] : [];
      }).slice(0, 8) : [],
      ...(typeof payload.conversation_id === 'string' ? { conversation_id: payload.conversation_id.slice(0, 120) } : {}),
    });
  } catch (error) {
    const timedOut = error instanceof Error && error.name === 'TimeoutError';
    const invalidOrigin = error instanceof TypeError && error.message.toLowerCase().includes('invalid url');
    return json({ message: timedOut ? 'SYNA phản hồi quá chậm. Vui lòng thử lại.' : 'SYNA tạm thời không kết nối được.' }, invalidOrigin ? 400 : timedOut ? 504 : 502);
  }
}
