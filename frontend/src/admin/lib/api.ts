export type AdminConfig = {
  tourId: number;
  restUrl: string;
  nonce: string;
  currency: import('@/lib/types').Currency;
  locale: string;
  today: string;
};

/** REST error as returned by WordPress (WP_Error → JSON). */
export class ApiError extends Error {
  readonly status: number;
  readonly fieldErrors: Record<string, string>;

  constructor(message: string, status: number, fieldErrors: Record<string, string> = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.fieldErrors = fieldErrors;
  }
}

export const readConfig = <T = AdminConfig>(id: string): T => {
  const node = document.getElementById(id);
  if (!node?.textContent) throw new Error(`Missing config #${id}`);
  return JSON.parse(node.textContent) as T;
};

/** Config printed by the Suntourz menu pages (Bookings, Settings). */
export type ShellConfig = {
  restUrl: string;
  nonce: string;
  currency: import('@/lib/types').Currency;
  locale: string;
  exportUrl: string;
  toursUrl: string;
  canSettings: boolean;
  openId: number;
  demo: { hasDemo: boolean };
};

type ErrorBody = { message?: string; data?: { errors?: Record<string, string> } };

export const createApi = (config: Pick<AdminConfig, 'restUrl' | 'nonce'>) => {
  const request = async <T>(path: string, init: RequestInit = {}): Promise<T> => {
    // Without pretty permalinks the base is ".../index.php?rest_route=/ns/", so a query string must join with "&".
    const relative = path.replace(/^\//, '');
    const url = config.restUrl.includes('?') ? config.restUrl + relative.replace('?', '&') : config.restUrl + relative;
    const response = await fetch(url, {
      ...init,
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.nonce,
        ...init.headers,
      },
    });

    const body: unknown = await response.json().catch(() => null);

    if (!response.ok) {
      const error = (body ?? {}) as ErrorBody;
      throw new ApiError(
        error.message ?? `Request failed (${response.status})`,
        response.status,
        error.data?.errors ?? {},
      );
    }

    return body as T;
  };

  return {
    get: <T>(path: string) => request<T>(path),
    put: <T>(path: string, data: unknown) =>
      request<T>(path, { method: 'PUT', body: JSON.stringify(data) }),
    patch: <T>(path: string, data: unknown) =>
      request<T>(path, { method: 'PATCH', body: JSON.stringify(data) }),
    del: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
    post: <T>(path: string, data: unknown) =>
      request<T>(path, { method: 'POST', body: JSON.stringify(data) }),
  };
};

export type Api = ReturnType<typeof createApi>;
