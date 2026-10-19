import { useMemo, useState } from 'react';
import { CheckCircle2, AlertTriangle, Image as ImageIcon } from 'lucide-react';
import { useStore } from '../store';
import { frameDoc } from '../frame';
import { Section, Row, Field, Area, Seg, Code, Select } from './ui';
import type { Seo } from '../../shared/types';
import { t } from '../i18n';

export const SeoPanel = () => {
  const doc = useStore((s) => s.doc);
  const ctx = useStore((s) => s.ctx);
  const cfg = useStore((s) => s.cfg);
  const url = useStore((s) => s.url);
  const setDoc = useStore((s) => s.setDoc);
  const [level, setLevel] = useState<'page' | 'template'>('page');
  if (!ctx) return <div className="ve-empty">{t('Loading…')}</div>;
  const key = level === 'page' ? ctx.page : 'tpl:' + ctx.template.key;
  const seo: Seo = doc.seo[key] ?? {};
  const set = (k: keyof Seo, v: unknown) =>
    setDoc((d) => {
      const cur = { ...(d.seo[key] ?? {}) } as Record<string, unknown>;
      if (v === '' || v === false || v === undefined) delete cur[k];
      else cur[k] = v;
      if (Object.keys(cur).length) d.seo[key] = cur as Seo;
      else delete d.seo[key];
    }, 'seo:' + key + k);

  const title = seo.title || ctx.title;
  const desc = seo.description || '';
  return (
    <div className="ve-scroll">
      <div className="ve-pad">
        <Seg value={level} onChange={setLevel} options={[{ value: 'page', label: t('This page') }, { value: 'template', label: t('Template') + ' · ' + ctx.template.label }]} />
        {level === 'template' && <p className="ve-faint">{t('Template values apply to every page of this kind. Use %title%, %site%, %excerpt%, %sep%.')}</p>}
      </div>
      <Section title={t('Search result')}>
        <div style={{ background: '#fff', color: '#202124', borderRadius: 8, padding: 12, fontFamily: 'arial, sans-serif' }} dir="auto">
          <div style={{ fontSize: 12, color: '#4d5156', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{decodeURI(url)}</div>
          <div style={{ fontSize: 18, color: '#1a0dab', margin: '2px 0', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{title.replace('%title%', ctx.title).replace('%site%', cfg.siteName).replace('%sep%', '–')}</div>
          <div style={{ fontSize: 13, color: '#4d5156', lineHeight: 1.5 }}>{desc || t('No description: search engines will pick some text from the page.')}</div>
        </div>
        <Row label={t('Title')}>
          <Field value={seo.title ?? ''} onChange={(v) => set('title', v)} placeholder={ctx.title} />
        </Row>
        <div className="ve-faint" style={{ color: (seo.title ?? '').length > 60 ? 'var(--ve-warn)' : undefined }}>{(seo.title ?? '').length}/60</div>
        <Row label={t('Description')}>
          <Area value={seo.description ?? ''} onChange={(v) => set('description', v)} rows={3} />
        </Row>
        <div className="ve-faint" style={{ color: (seo.description ?? '').length > 160 ? 'var(--ve-warn)' : undefined }}>{(seo.description ?? '').length}/160</div>
      </Section>
      <Section title={t('Sharing')} open={false}>
        <Row label={t('Image')}>
          <div style={{ display: 'flex', gap: 4 }}>
            <Field value={seo.image ?? ''} onChange={(v) => set('image', v)} placeholder="https://…" mono />
            <button className="ve-icon-btn" onClick={() => useStore.setState({ modal: { type: 'media', onPick: (m) => set('image', m.url) } })}>
              <ImageIcon size={14} />
            </button>
          </div>
        </Row>
        <Row label={t('OG title')}>
          <Field value={seo.ogTitle ?? ''} onChange={(v) => set('ogTitle', v)} placeholder={seo.title || ctx.title} />
        </Row>
        <Row label={t('OG text')}>
          <Area value={seo.ogDescription ?? ''} onChange={(v) => set('ogDescription', v)} rows={2} />
        </Row>
      </Section>
      <Section title={t('Indexing')} open={false}>
        <Row label={t('Canonical')}>
          <Field value={seo.canonical ?? ''} onChange={(v) => set('canonical', v)} placeholder={url} mono />
        </Row>
        <label className="ve-check"><input type="checkbox" checked={!!seo.noindex} onChange={(e) => set('noindex', e.target.checked)} /> noindex — {t('hide from search engines')}</label>
        <label className="ve-check"><input type="checkbox" checked={!!seo.nofollow} onChange={(e) => set('nofollow', e.target.checked)} /> nofollow</label>
      </Section>
      <Section title={t('Structured data (JSON-LD)')} open={false}>
        <Select value="" onChange={(type) => type && set('jsonld', JSON.stringify(schema(type, ctx.title, cfg.siteName, url, desc), null, 2))} placeholder={t('Generate…')} options={['Organization', 'TravelAgency', 'LocalBusiness', 'Product', 'Article', 'FAQPage', 'BreadcrumbList', 'TouristTrip']} />
        <Code value={seo.jsonld ?? ''} onChange={(v) => set('jsonld', v)} lang="js" height={180} />
        {seo.jsonld && !isJson(seo.jsonld) && <div style={{ color: 'var(--ve-warn)' }}>{t('Not valid JSON yet.')}</div>}
      </Section>
      <Checklist seo={seo} />
    </div>
  );
};

const isJson = (s: string) => {
  try {
    JSON.parse(s);
    return true;
  } catch {
    return false;
  }
};

const schema = (type: string, title: string, site: string, url: string, desc: string): Record<string, unknown> => {
  const base = { '@context': 'https://schema.org', '@type': type };
  const doc = frameDoc();
  switch (type) {
    case 'FAQPage':
      return {
        ...base,
        mainEntity: Array.from(doc?.querySelectorAll('details') ?? []).map((d) => ({ '@type': 'Question', name: d.querySelector('summary')?.textContent?.trim() ?? '', acceptedAnswer: { '@type': 'Answer', text: (d.textContent ?? '').replace(d.querySelector('summary')?.textContent ?? '', '').trim() } })),
      };
    case 'BreadcrumbList':
      return { ...base, itemListElement: [{ '@type': 'ListItem', position: 1, name: site, item: new URL(url).origin + '/' }, { '@type': 'ListItem', position: 2, name: title, item: url }] };
    case 'Product':
      return { ...base, name: title, description: desc, image: doc?.querySelector('main img')?.getAttribute('src') ?? '', offers: { '@type': 'Offer', price: '0', priceCurrency: 'USD', availability: 'https://schema.org/InStock', url } };
    case 'Article':
      return { ...base, headline: title, description: desc, mainEntityOfPage: url, publisher: { '@type': 'Organization', name: site } };
    case 'TouristTrip':
      return { ...base, name: title, description: desc, touristType: 'Leisure', provider: { '@type': 'TravelAgency', name: site } };
    default:
      return { ...base, name: site, url: new URL(url).origin + '/', description: desc, telephone: '', address: { '@type': 'PostalAddress', addressLocality: '', addressCountry: '' } };
  }
};

const Checklist = ({ seo }: { seo: Seo }) => {
  const frameKey = useStore((s) => s.frameKey);
  const loading = useStore((s) => s.loading);
  const checks = useMemo(() => {
    const d = frameDoc();
    if (!d || loading) return [];
    const h1 = d.querySelectorAll('h1').length;
    const imgs = Array.from(d.querySelectorAll('img'));
    const noAlt = imgs.filter((i) => !i.hasAttribute('alt')).length;
    const links = Array.from(d.querySelectorAll('a')).filter((a) => !(a.textContent ?? '').trim() && !a.getAttribute('aria-label') && !a.querySelector('img[alt]')).length;
    const lang = d.documentElement.lang;
    return [
      { ok: h1 === 1, text: h1 === 1 ? t('One H1 heading') : t('{n} H1 headings (use exactly one)', { n: h1 }) },
      { ok: !!seo.title || !!d.title, text: t('Page title set') },
      { ok: !!seo.description || !!d.querySelector('meta[name="description"]'), text: t('Meta description') },
      { ok: noAlt === 0, text: noAlt === 0 ? t('All images have alt text') : t('{n} images without alt text', { n: noAlt }) },
      { ok: links === 0, text: links === 0 ? t('Links have readable text') : t('{n} links without text', { n: links }) },
      { ok: !!lang, text: t('Language set') + (lang ? ` (${lang})` : '') },
      { ok: !!d.querySelector('meta[name="viewport"]'), text: t('Mobile viewport') },
    ];
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [frameKey, loading, seo]);
  return (
    <Section title={t('Checklist')}>
      {checks.map((c, i) => (
        <div key={i} style={{ display: 'flex', gap: 8, alignItems: 'center', color: c.ok ? 'var(--ve-text)' : 'var(--ve-warn)' }}>
          {c.ok ? <CheckCircle2 size={14} color="#22c55e" /> : <AlertTriangle size={14} />} {c.text}
        </div>
      ))}
    </Section>
  );
};
