import type { createApplier } from '@/lib/visualApply';
import type { VisualDoc } from '@/lib/visualCss';

/** The theme template that renders a URL (read from the preview). */
export type TemplateInfo = { key: string; label: string; templated: boolean; count: number };

export type TemplateEntry = { key: string; label: string; url: string; count: number; thumb: string; type: string };

export type ApiStatus = {
  enabled: boolean;
  level: 'read' | 'draft' | 'live';
  hasKey: boolean;
  keyHint: string;
  created: number;
  lastUsed: number;
  calls: number;
  endpoint: string;
  limits: { readsPerMinute: number; writesPerMinute: number; writesPerDay: number };
  draftChanges?: number;
};

export type EditorConfig = {
  restUrl: string;
  nonce: string;
  siteName: string;
  homeUrl: string;
  homePath: string;
  adminUrl: string;
  startUrl: string;
  previewArg: string;
  pages: { label: string; url: string; group: string }[];
  doc: VisualDoc;
  /** Administrators may change who can use the editor. */
  canAdmin: boolean;
  templates: TemplateEntry[];
  pageTypes: Record<string, string>;
  api: ApiStatus | null;
  draft: { changes: number } | null;
};

/** The loaded preview page (same origin, so the editor can read and change its DOM directly). */
export type Frame = {
  win: Window & typeof globalThis;
  doc: Document;
  styleEl: HTMLStyleElement;
  template: TemplateInfo;
  applier: ReturnType<typeof createApplier>;
};

export type Mode = 'inspect' | 'browse';

/** What a new edit is created from when the selected element has none yet. */
export type Target = { sel: string; label: string; scope: 'page' | 'template' | 'site'; page: string; tpl: string };
