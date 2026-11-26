import { Platform } from 'react-native';

import { API_BASE_URL, buildApiUrl, routes } from '../config/api';

export type SkinSyntaxProduct = {
  id: string;
  name: string;
  brand: string;
  category: string;
  price: number | null;
  imageUrl: string;
  imageUrls: string[];
  available?: boolean;
};

export type SynaTurn = { role: 'user' | 'assistant'; content: string };
export type SynaReply = {
  answer: string;
  products: SkinSyntaxProduct[];
  conflicts: string[];
  conversationId: string | null;
};

function normalizeConflicts(value: unknown): string[] {
  if (!Array.isArray(value)) return [];
  return value.flatMap(item => {
    if (typeof item === 'string') return [item.slice(0, 700)];
    if (!item || typeof item !== 'object') return [];
    const conflict = item as Row;
    const products = [conflict.product_a, conflict.product_b]
      .filter((name): name is string => typeof name === 'string' && !!name.trim())
      .map(name => name.trim());
    const warning = typeof conflict.warning === 'string' ? conflict.warning.trim() : '';
    const recommendation = typeof conflict.recommendation === 'string' ? conflict.recommendation.trim() : '';
    const text = [products.length ? `${products.join(' và ')}:` : '', warning, recommendation].filter(Boolean).join(' ');
    return text ? [text.slice(0, 700)] : [];
  }).slice(0, 8);
}

type Row = Record<string, unknown>;
type SmartSearchResponse = { type?: string; results?: Row[]; trending?: Row[] };

async function getPublicJson(url: string, signal?: AbortSignal): Promise<unknown> {
  const request = new AbortController();
  const cancel = () => request.abort();
  if (signal?.aborted) cancel();
  signal?.addEventListener('abort', cancel, { once: true });
  const timeout = setTimeout(cancel, 12000);
  try {
    const response = await fetch(url, {
      headers: { Accept: 'application/json' },
      credentials: 'omit',
      signal: request.signal,
    });
    if (!response.ok) throw new Error(`Máy chủ trả lỗi HTTP ${response.status}.`);
    if (!response.headers.get('content-type')?.includes('application/json')) {
      throw new Error('Máy chủ trả dữ liệu không đúng định dạng.');
    }
    return await response.json();
  } catch (error) {
    if (signal?.aborted) throw error;
    if (request.signal.aborted) throw new Error('Máy chủ phản hồi quá chậm. Vui lòng thử lại.');
    if (error instanceof TypeError) throw new Error('Không kết nối được. Kiểm tra mạng và thử lại.');
    if (error instanceof SyntaxError) throw new Error('Máy chủ trả dữ liệu không đúng định dạng.');
    throw error;
  } finally {
    clearTimeout(timeout);
    signal?.removeEventListener('abort', cancel);
  }
}

function splitImageValues(value: unknown): string[] {
  if (Array.isArray(value)) return value.flatMap(splitImageValues);
  if (typeof value !== 'string' || !value.trim()) return [];

  const trimmed = value.trim();
  if (trimmed.startsWith('[')) {
    try {
      return splitImageValues(JSON.parse(trimmed));
    } catch {
      // Some catalog records store a delimited list instead of valid JSON.
    }
  }
  return trimmed.split(/[|\r\n]+/).map(candidate => candidate.trim()).filter(Boolean);
}

function resolveImage(value: string): string {
  try {
    const base = new URL(API_BASE_URL.replace(/\/*$/, '/'));
    const image = new URL(value.trim(), base);
    if (image.username || image.password) return '';
    return image.protocol === 'https:' || (image.protocol === 'http:' && image.origin === base.origin)
      ? image.href : '';
  } catch {
    return '';
  }
}

function normalizeProduct(row: Row): SkinSyntaxProduct {
  const rawPrice = row.price ?? row.gia_ban;
  const numeric = typeof rawPrice === 'string' ? rawPrice.replace(/[^\d.-]/g, '') : rawPrice;
  const price = numeric === '' || numeric == null ? null : Number(numeric);
  const imageCandidates = [row.image_url, row.link_hinh_anh, row.hinh_anh, row.product_image_fallbacks_json]
    .flatMap(splitImageValues)
    .map(resolveImage)
    .filter(Boolean);
  const imageUrls = [...new Set(imageCandidates)].slice(0, 6);
  const available = typeof row.is_available === 'boolean'
    ? row.is_available
    : typeof row.trang_thai_kho === 'string' && row.trang_thai_kho.trim().toLowerCase() === 'het_hang'
      ? false : undefined;

  return {
    id: String(row.ma_san_pham ?? row.id ?? ''),
    name: String(row.ten_san_pham ?? row.name ?? 'Sản phẩm SkinSyntax'),
    brand: String(row.thuong_hieu ?? row.brand ?? ''),
    category: String(row.loai_san_pham ?? row.danh_muc_day_du ?? row.category ?? ''),
    price: price !== null && Number.isFinite(price) ? price : null,
    imageUrl: imageUrls[0] ?? '',
    imageUrls,
    ...(available === undefined ? {} : { available }),
  };
}

export async function searchProducts(query: string, signal?: AbortSignal) {
  const params = { q: query.trim() };
  const url = Platform.OS === 'web'
    ? `/api/catalog?${new URLSearchParams(params)}`
    : buildApiUrl(routes.smartSearch, params);
  const payload = await getPublicJson(url, signal) as SmartSearchResponse | null;
  const rows = payload?.type === 'live_search' ? payload.results
    : payload?.type === 'zero_query' ? payload.trending : null;
  if (!Array.isArray(rows)) throw new Error('Không nhận được danh sách sản phẩm hợp lệ.');
  return rows.filter(row => row && typeof row === 'object')
    .map(normalizeProduct)
    .filter(product => product.id);
}

export async function askSyna(message: string, history: SynaTurn[] = [], conversationId: string | null = null): Promise<SynaReply> {
  const url = Platform.OS === 'web' ? '/api/chat' : buildApiUrl(routes.aiChat);
  const request = new AbortController();
  const timeout = setTimeout(() => request.abort(), 75000);

  try {
    const response = await fetch(url, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      credentials: 'omit',
      signal: request.signal,
      body: JSON.stringify({
        message: message.trim().slice(0, 800),
        history: history.slice(-6).map(turn => ({ role: turn.role, content: turn.content.slice(0, 1200) })),
        ...(conversationId ? { conversation_id: conversationId } : {}),
      }),
    });

    if (!response.ok) throw new Error(`SYNA chưa thể trả lời (HTTP ${response.status}).`);
    if (!response.headers.get('content-type')?.includes('application/json')) {
      throw new Error('Máy chủ trả dữ liệu không đúng định dạng.');
    }

    const payload = await response.json() as Row;
    if (payload.ok !== true || typeof payload.answer !== 'string') {
      throw new Error(typeof payload.message === 'string' ? payload.message : 'SYNA chưa thể trả lời lúc này.');
    }

    const products = Array.isArray(payload.products)
      ? payload.products.filter((item): item is Row => !!item && typeof item === 'object')
        .map(normalizeProduct).filter(product => product.id)
      : [];
    const conflicts = normalizeConflicts(payload.conflicts);

    return {
      answer: payload.answer,
      products,
      conflicts,
      conversationId: typeof payload.conversation_id === 'string' ? payload.conversation_id : conversationId,
    };
  } catch (error) {
    if (request.signal.aborted) throw new Error('SYNA phản hồi quá chậm. Vui lòng thử lại.');
    if (error instanceof TypeError) throw new Error('Không kết nối được. Kiểm tra mạng và thử lại.');
    if (error instanceof SyntaxError) throw new Error('Máy chủ trả dữ liệu không đúng định dạng.');
    throw error;
  } finally {
    clearTimeout(timeout);
  }
}
