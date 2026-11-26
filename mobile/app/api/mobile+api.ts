import { buildApiUrl, routes } from '../../src/config/api';

const COOKIE_NAME = 'ss_mobile_session';
const responseHeaders = { 'Cache-Control': 'no-store', Vary: 'Cookie' };
const allowedMethods: Record<string, string[]> = {
  'auth.login': ['POST'],
  'auth.me': ['GET'],
  'auth.logout': ['POST'],
  'catalog.product': ['GET'],
  'favorites.list': ['GET'],
  'favorites.set': ['PUT'],
  'cart.get': ['GET'],
  'cart.add': ['POST'],
  'cart.set': ['PUT'],
  'cart.remove': ['DELETE'],
  'cart.clear': ['DELETE'],
  'account.profile': ['GET', 'PATCH'],
  'account.addresses': ['GET', 'POST', 'PATCH', 'DELETE'],
  'account.skin-profile': ['GET', 'PATCH', 'POST'],
  'account.orders': ['GET'],
  'account.loyalty': ['GET'],
  'account.vouchers': ['GET'],
};

export function GET(request: Request) { return forward(request); }
export function POST(request: Request) { return forward(request); }
export function PUT(request: Request) { return forward(request); }
export function PATCH(request: Request) { return forward(request); }
export function DELETE(request: Request) { return forward(request); }

async function forward(request: Request): Promise<Response> {
  const requestUrl = new URL(request.url);
  const action = requestUrl.searchParams.get('action') ?? '';
  const method = request.method.toUpperCase();
  if (!allowedMethods[action]) {
    return Response.json({ ok: false, message: 'API route không tồn tại.' }, { status: 404, headers: responseHeaders });
  }
  if (!allowedMethods[action].includes(method)) {
    return Response.json({ ok: false, message: 'Phương thức không được hỗ trợ.' }, { status: 405, headers: responseHeaders });
  }

  const mutating = ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method);
  if (mutating && request.headers.get('origin') !== requestUrl.origin) {
    return Response.json({ ok: false, message: 'Nguồn yêu cầu không hợp lệ.' }, { status: 403, headers: responseHeaders });
  }

  let body: string | undefined;
  if (mutating && (method !== 'DELETE' || request.headers.get('content-length') !== '0')) {
    try {
      body = await request.text();
    } catch {
      return Response.json({ ok: false, message: 'Nội dung yêu cầu không hợp lệ.' }, { status: 400, headers: responseHeaders });
    }
    if (body.length > 32768) {
      return Response.json({ ok: false, message: 'Nội dung gửi lên quá lớn.' }, { status: 413, headers: responseHeaders });
    }
    if (body !== '' && !request.headers.get('content-type')?.toLowerCase().includes('application/json')) {
      return Response.json({ ok: false, message: 'Yêu cầu phải dùng JSON.' }, { status: 415, headers: responseHeaders });
    }
  }

  const cookieToken = readSessionCookie(request.headers.get('cookie') ?? '');
  const publicActions = new Set(['auth.login', 'catalog.product']);
  if (!publicActions.has(action) && !cookieToken) {
    return Response.json({ ok: false, code: 'authentication_required', message: 'Vui lòng đăng nhập lại.' }, { status: 401, headers: responseHeaders });
  }

  const upstreamUrl = new URL(buildApiUrl(routes.mobileApi, { action }));
  for (const key of ['id', 'profile_id', 'product_id']) {
    const value = requestUrl.searchParams.get(key);
    if (value !== null && value.length <= 120) upstreamUrl.searchParams.set(key, value);
  }

  const headers: Record<string, string> = { Accept: 'application/json' };
  if (body !== undefined && body !== '') headers['Content-Type'] = request.headers.get('content-type')?.includes('application/json')
    ? 'application/json' : 'text/plain';
  if (cookieToken) headers.Authorization = `Bearer ${cookieToken}`;
  const idempotencyKey = request.headers.get('idempotency-key');
  if (idempotencyKey && idempotencyKey.length <= 120) headers['Idempotency-Key'] = idempotencyKey;

  try {
    const upstream = await fetch(upstreamUrl, {
      method,
      headers,
      ...(body === undefined ? {} : { body }),
      credentials: 'omit',
      redirect: 'manual',
      signal: AbortSignal.timeout(20000),
    });
    const contentType = upstream.headers.get('content-type') ?? '';
    if (upstream.status >= 300 && upstream.status < 400 || !contentType.includes('application/json')) {
      const mobileApiNotDeployed = upstream.status === 404;
      const message = mobileApiNotDeployed
        ? 'API Mobile chưa được triển khai trên máy chủ. Vui lòng cập nhật backend rồi thử lại.'
        : 'Máy chủ SkinSyntax chưa trả dữ liệu API hợp lệ.';
      return Response.json({ ok: false, message }, {
        status: upstream.status === 401 ? 401 : mobileApiNotDeployed ? 503 : 502,
        headers: responseHeaders,
      });
    }

    const payload = await upstream.json() as Record<string, unknown>;
    const headersOut = new Headers(responseHeaders);
    if (upstream.status === 401 && !publicActions.has(action)) {
      const secure = requestUrl.protocol === 'https:' ? '; Secure' : '';
      headersOut.append('Set-Cookie', `${COOKIE_NAME}=; Path=/api/mobile; Max-Age=0; HttpOnly; SameSite=Strict${secure}`);
    }
    if (action === 'auth.login' && upstream.ok && typeof payload.token === 'string') {
      const token = payload.token;
      delete payload.token;
      const secure = requestUrl.protocol === 'https:' ? '; Secure' : '';
      headersOut.append('Set-Cookie', `${COOKIE_NAME}=${token}; Path=/api/mobile; Max-Age=2592000; HttpOnly; SameSite=Strict${secure}`);
    }
    if (action === 'auth.logout' && upstream.ok) {
      const secure = requestUrl.protocol === 'https:' ? '; Secure' : '';
      headersOut.append('Set-Cookie', `${COOKIE_NAME}=; Path=/api/mobile; Max-Age=0; HttpOnly; SameSite=Strict${secure}`);
    }
    return Response.json(payload, { status: upstream.status, headers: headersOut });
  } catch {
    const headersOut = new Headers(responseHeaders);
    if (action === 'auth.logout') {
      const secure = requestUrl.protocol === 'https:' ? '; Secure' : '';
      headersOut.append('Set-Cookie', `${COOKIE_NAME}=; Path=/api/mobile; Max-Age=0; HttpOnly; SameSite=Strict${secure}`);
    }
    return Response.json({ ok: false, message: 'Không kết nối được máy chủ SkinSyntax. Vui lòng thử lại.' }, {
      status: 502,
      headers: headersOut,
    });
  }
}

function readSessionCookie(cookieHeader: string): string {
  for (const entry of cookieHeader.split(';')) {
    const separator = entry.indexOf('=');
    if (separator < 0 || entry.slice(0, separator).trim() !== COOKIE_NAME) continue;
    const token = entry.slice(separator + 1).trim();
    return /^[A-Za-z0-9_-]{40,64}$/.test(token) ? token : '';
  }
  return '';
}
