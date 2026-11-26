export const PRODUCTION_API_URL = 'https://skinsyntaxvn.app';

const isDevelopmentApi = __DEV__ && process.env.EXPO_PUBLIC_API_MODE === 'development';

export const apiMode = isDevelopmentApi ? 'development' : 'production';
export const API_BASE_URL = isDevelopmentApi
  ? process.env.EXPO_PUBLIC_API_BASE_URL?.trim() ?? ''
  : PRODUCTION_API_URL;

export const routes = {
  smartSearch: 'api_smart_search',
  productDetail: 'chitiet',
  aiChat: 'ai_chat_api',
  mobileApi: 'mobile_api',
  routineWebsite: 'goiy',
  cart: 'giohang',
  account: 'hoso',
  favorites: 'yeu_thich',
  survey: 'khaosat',
  quickSurvey: 'khaosatnhanh',
  orders: 'hoso',
  login: 'dangnhap',
  register: 'dangky',
  privacy: 'chinh_sach_bao_mat',
  contact: 'lien_he',
  livestream: 'live',
} as const;

export function buildApiUrl(route: (typeof routes)[keyof typeof routes], params: Record<string, string> = {}) {
  const base = new URL(API_BASE_URL);
  if (!['https:', 'http:'].includes(base.protocol) || base.username || base.password || base.search || base.hash) {
    throw new Error('Địa chỉ máy chủ không hợp lệ.');
  }
  base.pathname = base.pathname.replace(/\/*$/, '/') + 'index.php';
  base.searchParams.set('r', route);
  for (const [key, value] of Object.entries(params)) {
    if (key !== 'r') base.searchParams.set(key, value);
  }
  return base.href;
}
