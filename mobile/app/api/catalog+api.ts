import { buildApiUrl, routes } from '../../src/config/api';

const responseHeaders = { 'Cache-Control': 'no-store' };

export async function GET(request: Request) {
  const q = new URL(request.url).searchParams.get('q')?.trim() ?? '';
  if (q.length > 120) {
    return Response.json({ error: 'Query is too long.' }, { status: 400, headers: responseHeaders });
  }

  try {
    // Keep the destination fixed by app configuration. Forward no browser credentials or headers.
    const upstream = await fetch(buildApiUrl(routes.smartSearch, { q }), {
      headers: { Accept: 'application/json' },
      credentials: 'omit',
      redirect: 'error',
      signal: AbortSignal.timeout(10_000),
    });

    if (!upstream.ok || !upstream.headers.get('content-type')?.includes('application/json')) {
      return Response.json({ error: 'Catalog unavailable.' }, {
        status: upstream.status === 429 ? 429 : 502,
        headers: responseHeaders,
      });
    }

    const data = await upstream.json();
    if (data?.type === 'live_search' && Array.isArray(data.results)) {
      return Response.json({ type: data.type, results: data.results }, { headers: responseHeaders });
    }
    if (data?.type === 'zero_query' && Array.isArray(data.trending)) {
      return Response.json({ type: data.type, trending: data.trending }, { headers: responseHeaders });
    }
    return Response.json({ error: 'Invalid catalog response.' }, { status: 502, headers: responseHeaders });
  } catch (error) {
    return Response.json({ error: 'Catalog unavailable.' }, {
      status: error instanceof Error && error.name === 'TimeoutError' ? 504 : 502,
      headers: responseHeaders,
    });
  }
}
