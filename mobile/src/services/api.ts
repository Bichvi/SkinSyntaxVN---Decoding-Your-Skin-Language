const DEFAULT_TIMEOUT_MS = 12000;

export const API_BASE_URL = (process.env.EXPO_PUBLIC_API_BASE_URL ?? '').replace(/\/+$/, '');

export type SkinSyntaxProduct = {
  id: string;
  name: string;
  price: number | null;
  marketPrice: number | null;
  imageUrl: string;
  brandId: string;
  categoryId: string;
  raw: Record<string, unknown>;
};

export type SmartSearchResponse = {
  type?: string;
  results?: Record<string, unknown>[];
  trending?: Record<string, unknown>[];
  history?: string[];
  message?: string;
};

type RequestOptions = RequestInit & {
  timeoutMs?: number;
};

function requireBaseUrl(): string {
  if (!API_BASE_URL) {
    throw new Error('EXPO_PUBLIC_API_BASE_URL is not configured.');
  }

  return API_BASE_URL;
}

function buildUrl(path: string, params?: Record<string, string | number | boolean | undefined>): string {
  const url = new URL(path, requireBaseUrl());
  Object.entries(params ?? {}).forEach(([key, value]) => {
    if (value !== undefined) {
      url.searchParams.set(key, String(value));
    }
  });

  return url.toString();
}

export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { timeoutMs = DEFAULT_TIMEOUT_MS, ...requestOptions } = options;
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetch(buildUrl(path), {
      ...requestOptions,
      headers: {
        Accept: 'application/json',
        ...(requestOptions.headers ?? {}),
      },
      signal: controller.signal,
    });

    const text = await response.text();
    const payload = text ? JSON.parse(text) : null;

    if (!response.ok) {
      const message =
        payload && typeof payload === 'object' && 'message' in payload
          ? String((payload as { message?: unknown }).message)
          : `SkinSyntax API returned HTTP ${response.status}`;
      throw new Error(message);
    }

    return payload as T;
  } finally {
    clearTimeout(timeout);
  }
}

export function normalizeProduct(product: Record<string, unknown>): SkinSyntaxProduct {
  return {
    id: String(product.ma_san_pham ?? product.id ?? product._id ?? ''),
    name: String(product.ten_san_pham ?? product.name ?? 'Sản phẩm SkinSyntax'),
    price: toNumber(product.gia_ban),
    marketPrice: toNumber(product.gia_thi_truong),
    imageUrl: String(product.image_url ?? product.link_hinh_anh ?? product.hinh_anh ?? ''),
    brandId: String(product.ma_thuong_hieu ?? ''),
    categoryId: String(product.ma_danh_muc ?? ''),
    raw: product,
  };
}

export async function searchProducts(query: string): Promise<{
  response: SmartSearchResponse;
  products: SkinSyntaxProduct[];
}> {
  const url = buildUrl('/index.php', {
    r: 'api_smart_search',
    q: query,
  });

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), DEFAULT_TIMEOUT_MS);

  try {
    const response = await fetch(url, {
      headers: {
        Accept: 'application/json',
      },
      signal: controller.signal,
    });
    const payload = (await response.json()) as SmartSearchResponse;

    if (!response.ok) {
      throw new Error(payload.message ?? `SkinSyntax API returned HTTP ${response.status}`);
    }

    const rows = Array.isArray(payload.results) ? payload.results : Array.isArray(payload.trending) ? payload.trending : [];

    return {
      response: payload,
      products: rows.map(normalizeProduct),
    };
  } finally {
    clearTimeout(timeout);
  }
}

function toNumber(value: unknown): number | null {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value;
  }

  if (typeof value === 'string') {
    const parsed = Number(value.replace(/[^\d.-]/g, ''));
    return Number.isFinite(parsed) ? parsed : null;
  }

  return null;
}
