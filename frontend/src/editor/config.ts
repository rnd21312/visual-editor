import type { Doc } from '../shared/types';

export type TemplateInfo = { key: string; label: string; url: string; count: number; thumb: string; type: string };
export type BuilderTemplate = { id: number; title: string; target: string; mode: string; active: boolean; priority: number; conditions: { include?: number[]; exclude?: number[] }; replace: string; modified: string };
export type PostType = { name: string; label: string; singular: string; hierarchical: boolean; taxonomies: string[]; archive: string; canCreate: boolean };
export type Taxonomy = { name: string; label: string; hierarchical: boolean; types: string[] };
export type Library = { id: string; name: string; category: string; version: string; deps: string[]; usage: string; assets: { type: string; url: string }[] };
export type ApiKey = { id: string; label: string; hint: string; level: string; user: string; created: number; lastUsed: number; calls: number };
export type ApiStatus = { enabled: boolean; endpoint: string; about: string; serverName: string; apps: { name: string; created: number; last_used: number }[]; keys: ApiKey[]; limits: Record<string, number>; live: { online: boolean; seen: number; user: string; url: string } };

export type Config = {
  restUrl: string;
  wpRestUrl: string;
  nonce: string;
  siteName: string;
  homeUrl: string;
  adminUrl: string;
  logoutUrl: string;
  startUrl: string;
  startPost: number;
  previewArg: string;
  doc: Doc;
  user: { id: number; name: string; admin: boolean; trusted: boolean };
  language: string;
  rtl: boolean;
  templates: TemplateInfo[];
  builder: BuilderTemplate[];
  types: Record<string, PostType>;
  taxonomies: Taxonomy[];
  libraries: Library[];
  menuLocations: Record<string, string>;
  api: ApiStatus | null;
  draft: number;
  ssr: boolean;
  woocommerce: boolean;
  version: string;
  react: string;
};

export const readConfig = (): Config => {
  const el = document.getElementById('sve-config');
  if (!el?.textContent) throw new Error('Missing #sve-config');
  return JSON.parse(el.textContent) as Config;
};
