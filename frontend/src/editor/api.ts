import type { Config } from './config';

export class ApiError extends Error {
  constructor(message: string, public status: number, public body: unknown) {
    super(message);
  }
}

export type Api = {
  get: <T = unknown>(path: string, params?: Record<string, unknown>) => Promise<T>;
  post: <T = unknown>(path: string, body?: unknown) => Promise<T>;
  put: <T = unknown>(path: string, body?: unknown) => Promise<T>;
  del: <T = unknown>(path: string) => Promise<T>;
  upload: <T = unknown>(path: string, file: File) => Promise<T>;
};

export const createApi = (cfg: Config): Api => {
  const url = (path: string, params?: Record<string, unknown>) => {
    const clean = path.replace(/^\//, '');
    const base = cfg.restUrl.includes('?') ? cfg.restUrl + clean.replace('?', '&') : cfg.restUrl + clean;
    if (!params) return base;
    const qs = Object.entries(params)
      .filter(([, v]) => v !== undefined && v !== null && v !== '')
      .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
      .join('&');
    return qs ? base + (base.includes('?') ? '&' : '?') + qs : base;
  };

  const request = async <T>(method: string, path: string, body?: unknown, params?: Record<string, unknown>, raw?: BodyInit): Promise<T> => {
    const res = await fetch(url(path, params), {
      method,
      credentials: 'same-origin',
      headers: raw ? { 'X-WP-Nonce': cfg.nonce } : { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
      body: raw ?? (body === undefined ? undefined : JSON.stringify(body)),
    });
    const data = await res.json().catch(() => null);
    if (!res.ok && res.status !== 409) {
      const msg = (data as { message?: string } | null)?.message ?? `Request failed (${res.status})`;
      throw new ApiError(msg, res.status, data);
    }
    if (res.status === 409) throw new ApiError('conflict', 409, data);
    return data as T;
  };

  return {
    get: (p, params) => request('GET', p, undefined, params),
    post: (p, b) => request('POST', p, b),
    put: (p, b) => request('PUT', p, b),
    del: (p) => request('DELETE', p),
    upload: (p, file) => {
      const fd = new FormData();
      fd.append('file', file);
      return request('POST', p, undefined, undefined, fd);
    },
  };
};
