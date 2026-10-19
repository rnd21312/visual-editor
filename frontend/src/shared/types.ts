// Shapes of the editor document (mirrors src/Sanitizer.php).

export type Device = 'base' | 'tablet' | 'mobile';
export type Scope = 'page' | 'template' | 'site';
export type StateName =
  | 'normal'
  | 'hover'
  | 'focus'
  | 'active'
  | 'focus-visible'
  | 'focus-within'
  | 'first-child'
  | 'last-child'
  | 'before'
  | 'after'
  | 'placeholder';

export type StyleBag = Partial<Record<Device, Record<string, string>>>;

export type Motion = {
  preset: string;
  trigger: 'load' | 'scroll' | 'hover' | 'click' | 'scrub';
  duration: number;
  delay: number;
  stagger: number;
  distance: number;
  repeat: number;
  yoyo: boolean;
  once: boolean;
  ease: string;
  start: string;
  from?: Record<string, string | number>;
  to?: Record<string, string | number>;
};

export type Edit = {
  id: string;
  scope: Scope;
  page: string;
  tpl: string;
  sel: string;
  label: string;
  styles: StyleBag;
  states?: Partial<Record<Exclude<StateName, 'normal'>, StyleBag>>;
  css: string;
  compiled?: string;
  hide?: Partial<Record<'desktop' | 'tablet' | 'mobile', boolean>>;
  text?: string;
  html?: string;
  attrs?: Record<string, string | null>;
  motion?: Motion;
  trusted?: boolean;
};

export type Op = {
  id: string;
  kind: 'insert' | 'move' | 'remove' | 'tag';
  scope: Scope;
  page: string;
  tpl: string;
  label: string;
  sel: string;
  pos?: 'before' | 'after' | 'prepend' | 'append';
  html?: string;
  comp?: string;
  props?: Record<string, unknown>;
  exec?: boolean;
  to?: string;
  tag?: string;
  trusted?: boolean;
};

export type PropDef = {
  name: string;
  label: string;
  type: 'text' | 'html' | 'url' | 'image' | 'number' | 'bool' | 'color' | 'select' | 'json' | 'list';
  default: unknown;
  options: string[];
};

export type Component = {
  id: string;
  name: string;
  kind: 'html' | 'section' | 'embed' | 'jsonld' | 'react';
  category: string;
  html: string;
  css: string;
  js?: string;
  jsx?: string;
  compiled?: string;
  imports?: string[];
  props: PropDef[];
  updated?: number;
  trusted?: boolean;
};

export type Script = {
  id: string;
  label: string;
  code: string;
  where: 'head' | 'footer';
  module: boolean;
  enabled: boolean;
  scope: Scope;
  page: string;
  tpl: string;
};

export type LibraryUse = {
  id: string;
  scope: 'site' | 'page' | 'template';
  pages: string[];
  tpls: string[];
  enabled: boolean;
  url?: string;
  type?: 'script' | 'module' | 'style';
  name?: string;
};

export type Seo = {
  title?: string;
  description?: string;
  ogTitle?: string;
  ogDescription?: string;
  keywords?: string;
  image?: string;
  canonical?: string;
  noindex?: boolean;
  nofollow?: boolean;
  jsonld?: string;
};

export type Font = { family: string; source: 'google' | 'bunny' | 'url' | 'system'; weights: number[]; role: '' | 'body' | 'heading' | 'mono' | 'accent'; url?: string };

export type Doc = {
  v: number;
  rev: number;
  updated?: number;
  css: string;
  edits: Edit[];
  ops: Op[];
  components: Component[];
  scripts: Script[];
  libraries: LibraryUse[];
  seo: Record<string, Seo>;
  tokens: { vars: Record<string, string>; fonts: Font[] };
  tailwind: { enabled: boolean; css: string; config: string };
  settings: { ssr: 'auto' | 'on' | 'off'; reducedMotion: boolean; breakpoints: { tablet: number; mobile: number } };
  access: 'admin' | 'editor';
};

export type ContentEdit = { sel: string; text?: string; html?: string; attrs?: Record<string, string | null>; t?: number };
export type RuntimeOp = Omit<Op, 'scope' | 'page' | 'tpl' | 'label' | 'props' | 'trusted'> & { t?: number };
