import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

import { buildApiUrl, routes } from '@/src/config/api';

const TOKEN_KEY = 'skinsyntax.mobile.access.v1';
let accessToken: string | null = null;
const unauthorizedListeners = new Set<() => void>();

export class MobileApiError extends Error {
  constructor(message: string, public readonly status: number, public readonly code?: string) {
    super(message);
    this.name = 'MobileApiError';
  }
}

export type MobileUser = {
  id: string;
  ma_kh: number;
  ho_ten: string;
  email: string;
  role: 'khach_hang';
};

export function subscribeToUnauthorized(listener: () => void) {
  unauthorizedListeners.add(listener);
  return () => { unauthorizedListeners.delete(listener); };
}

export async function restoreAccessToken(): Promise<string | null> {
  if (Platform.OS === 'web') return null;
  accessToken = await SecureStore.getItemAsync(TOKEN_KEY);
  return accessToken;
}

export async function persistAccessToken(token: string): Promise<void> {
  if (Platform.OS !== 'web') await SecureStore.setItemAsync(TOKEN_KEY, token);
  accessToken = token;
}

export async function forgetAccessToken(): Promise<void> {
  accessToken = null;
  if (Platform.OS !== 'web') await SecureStore.deleteItemAsync(TOKEN_KEY);
}

export async function mobileApiRequest<T = Record<string, unknown>>(
  action: string,
  options: {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    body?: Record<string, unknown>;
    params?: Record<string, string>;
    token?: string | null;
    idempotencyKey?: string;
  } = {},
): Promise<T> {
  const method = options.method ?? 'GET';
  const web = Platform.OS === 'web';
  const token = options.token !== undefined ? options.token : accessToken;
  const query = { action, ...(options.params ?? {}) };
  const url = web
    ? `/api/mobile?${new URLSearchParams(query)}`
    : buildApiUrl(routes.mobileApi, query);
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (options.body !== undefined) headers['Content-Type'] = 'application/json';
  if (!web && token) headers.Authorization = `Bearer ${token}`;
  if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey;

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 20000);
  try {
    const response = await fetch(url, {
      method,
      headers,
      ...(options.body === undefined ? {} : { body: JSON.stringify(options.body) }),
      credentials: web ? 'same-origin' : 'omit',
      redirect: 'error',
      signal: controller.signal,
    });
    const contentType = response.headers.get('content-type') ?? '';
    if (!contentType.includes('application/json')) {
      throw new MobileApiError('Máy chủ trả dữ liệu không đúng định dạng.', response.status || 502);
    }
    const payload = await response.json() as Record<string, unknown>;
    if (!response.ok || payload.ok === false) {
      if (response.status === 401 && action !== 'auth.login') {
        unauthorizedListeners.forEach(listener => listener());
      }
      throw new MobileApiError(
        typeof payload.message === 'string' ? payload.message : 'Yêu cầu chưa thành công.',
        response.status,
        typeof payload.code === 'string' ? payload.code : undefined,
      );
    }
    return payload as T;
  } catch (error) {
    if (controller.signal.aborted) throw new MobileApiError('Máy chủ phản hồi quá chậm. Vui lòng thử lại.', 408);
    if (error instanceof MobileApiError) throw error;
    if (error instanceof TypeError) throw new MobileApiError('Không kết nối được. Kiểm tra mạng và thử lại.', 0);
    throw error;
  } finally {
    clearTimeout(timeout);
  }
}

export function createIdempotencyKey(): string {
  const bytes = new Uint8Array(18);
  if (globalThis.crypto?.getRandomValues) globalThis.crypto.getRandomValues(bytes);
  else for (let index = 0; index < bytes.length; index++) bytes[index] = Math.floor(Math.random() * 256);
  return Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
}

export type CartLine = {
  product_id: string;
  quantity: number;
  unit_price: number;
  line_total: number;
  stock: number;
  product: {
    id: string;
    name: string;
    brand: string;
    category: string;
    price: number;
    image_url: string;
    available: boolean;
  };
};

export type CustomerCart = {
  revision: number;
  items: CartLine[];
  item_count: number;
  subtotal: number;
  warnings: { product_id: string; code: string }[];
};
