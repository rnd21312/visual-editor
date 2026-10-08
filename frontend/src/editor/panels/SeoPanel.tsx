import { useMemo, useState } from 'react';
import { templateSeoKey, type Seo } from '@/lib/visualCss';
import { Row, Section } from '../controls';
import { useEditor } from '../context';
import { isPlaceholder, isRendered } from '../selector';

const FALLBACK = '#wpadminbar';
const LANDMARKS = ['section', 'article', 'nav', 'aside', 'header', 'footer', 'main', 'figure', 'div'];

type Issue = { level: 'error' | 'warn' | 'ok'; text: string; el?: HTMLElement };

const visible = (doc: Document, selector: string): HTMLElement[] =>
  Array.from(doc.querySelectorAll<HTMLElement>(selector)).filter(
    (el) => !isPlaceholder(el) && !el.closest(FALLBACK) && isRendered(el), // hidden theme variants do not count
  );

const counter = (value: string, min: number, max: number) => {
  const length = value.length;
  const state = length === 0 ? 'is-empty' : length < min || length > max ? 'is-warn' : 'is-ok';
  return <span className={`ve-counter ${state}`}>{length} / {min}–{max}</span>;
};

/** Everything an SEO review of the previewed page should flag. */
const audit = (doc: Document, seo: Seo): Issue[] => {
  const issues: Issue[] = [];
  const title = (seo.title ?? doc.title).trim();
  const metaDescription = doc.querySelector<HTMLMetaElement>('meta[name="description"]')?.content ?? '';
  const description = (seo.description ?? metaDescription).trim();

  issues.push(
    title === ''
      ? { level: 'error', text: 'The page has no title.' }
      : title.length > 60
        ? { level: 'warn', text: `Title is ${title.length} characters — Google cuts it around 60.` }
        : { level: 'ok', text: `Title length is fine (${title.length}).` },
  );
  issues.push(
    description === ''
      ? { level: 'error', text: 'No meta description. Add one in “Page SEO” below.' }
      : description.length > 160 || description.length < 70
        ? { level: 'warn', text: `Description is ${description.length} characters — aim for 70–160.` }
        : { level: 'ok', text: `Description length is fine (${description.length}).` },
  );

  const h1s = visible(doc, 'h1');
  issues.push(
    h1s.length === 1
      ? { level: 'ok', text: 'Exactly one H1.', el: h1s[0] }
      : h1s.length === 0
        ? { level: 'error', text: 'No H1 on the page.' }
        : { level: 'error', text: `${h1s.length} H1 headings — keep one per page.`, el: h1s[1] },
  );

  let previous = 0;
  for (const heading of visible(doc, 'h1, h2, h3, h4, h5, h6')) {
    const level = Number(heading.localName.slice(1));
    if (previous && level > previous + 1) {
      issues.push({ level: 'warn', text: `Heading jumps from H${previous} to H${level}.`, el: heading });
      break;
    }
    previous = level;
  }

  for (const img of visible(doc, 'img').filter((el) => !el.hasAttribute('alt')).slice(0, 4)) {
    issues.push({ level: 'error', text: 'Image without alt text.', el: img });
  }
  for (const img of visible(doc, 'img').filter((el) => !el.getAttribute('width') && !el.getAttribute('height') && !el.className.includes('h-')).slice(0, 2)) {
    issues.push({ level: 'warn', text: 'Image without width/height (layout shift).', el: img });
  }

  for (const link of visible(doc, 'a[href]').slice(0, 400)) {
    const name = (link.textContent ?? '').trim() || link.getAttribute('aria-label') || link.querySelector('img[alt]')?.getAttribute('alt');
    if (!name) {
      issues.push({ level: 'warn', text: 'Link without readable text.', el: link });
      break;
    }
  }
  const unsafe = visible(doc, 'a[target="_blank"]').find((el) => !/noopener|noreferrer/.test(el.getAttribute('rel') ?? ''));
  if (unsafe) issues.push({ level: 'warn', text: 'Link opens in a new tab without rel="noopener".', el: unsafe });

  return issues;
};

export const SeoPanel = () => {
  const { doc, frame, page, template, selected, version, patchDoc, patch, activeEdit, target, actions, select } = useEditor();
  const [level, setLevel] = useState<'page' | 'template'>(template.templated ? 'template' : 'page');
  const seoKey = level === 'template' && template.templated ? templateSeoKey(template.key) : page;
  const seo: Seo = doc.seo?.[seoKey] ?? {};

  const setSeo = (change: Partial<Seo>) =>
    patchDoc((current) => {
      const next = { ...(current.seo?.[seoKey] ?? {}), ...change };
      for (const key of Object.keys(next) as (keyof Seo)[]) {
        if (next[key] === '' || next[key] === false || next[key] === undefined) delete next[key];
      }
      const all = { ...(current.seo ?? {}) };
      if (Object.keys(next).length === 0) delete all[seoKey];
      else all[seoKey] = next;
      return { ...current, seo: all };
    }, `seo:${seoKey}:${Object.keys(change).join(',')}`);

  const issues = useMemo(
    () => (frame ? audit(frame.doc, seo) : []),
    // `version` follows DOM changes in the preview.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [frame, seo, version],
  );

  const jsonError = (() => {
    if (!seo.jsonld?.trim()) return '';
    try {
      JSON.parse(seo.jsonld);
      return '';
    } catch {
      return 'Not valid JSON yet.';
    }
  })();

  const attrs = activeEdit?.attrs ?? {};
  const attr = (name: string) => attrs[name] ?? selected?.getAttribute(name) ?? '';
  const setAttr = (name: string, value: string | null) =>
    patch(
      (edit) => ({ ...edit, attrs: { ...(edit.attrs ?? {}), [name]: value } }),
      `seo-attr:${target?.sel ?? ''}:${name}`,
    );

  const tag = selected?.localName ?? '';
  const isHeading = /^h[1-6]$/.test(tag);

  const errors = issues.filter((issue) => issue.level === 'error').length;
  const warns = issues.filter((issue) => issue.level === 'warn').length;
  const pageTitle = seo.title ?? frame?.doc.title ?? '';
  const pageDescription =
    seo.description ?? frame?.doc.querySelector<HTMLMetaElement>('meta[name="description"]')?.content ?? '';

  return (
    <div className="ve-panel-body">
      {selected && (
        <Section title={`This element (${tag})`} defaultOpen>
          {isHeading && (
            <Row label="Heading level">
              <div className="ve-segment" role="group">
                {['h1', 'h2', 'h3', 'h4', 'h5', 'h6'].map((level) => (
                  <button key={level} type="button" className={tag === level ? 'is-on' : ''} onClick={() => actions.retag(level)}>
                    {level.toUpperCase()}
                  </button>
                ))}
              </div>
            </Row>
          )}
          {!isHeading && ['div', 'span', 'section', 'article', 'nav', 'aside', 'header', 'footer', 'main', 'figure'].includes(tag) && (
            <Row label="Semantic tag">
              <select className="ve-input" value={tag} onChange={(event) => actions.retag(event.target.value)} aria-label="Tag">
                {Array.from(new Set([tag, ...LANDMARKS])).map((name) => (
                  <option key={name} value={name}>
                    {`<${name}>`}
                  </option>
                ))}
              </select>
            </Row>
          )}
          {tag === 'img' && (
            <>
              <Row label="Alt text" touched={'alt' in attrs}>
                <input className="ve-input" value={attr('alt')} placeholder="Describe the image" onChange={(event) => setAttr('alt', event.target.value)} aria-label="Alt text" />
              </Row>
              <Row label="Title" touched={'title' in attrs}>
                <input className="ve-input" value={attr('title')} onChange={(event) => setAttr('title', event.target.value)} aria-label="Image title" />
              </Row>
              <Row label="Loading">
                <select className="ve-input" value={attr('loading')} onChange={(event) => setAttr('loading', event.target.value === '' ? null : event.target.value)} aria-label="Loading">
                  <option value="">default</option>
                  <option value="lazy">lazy (below the fold)</option>
                  <option value="eager">eager (hero / LCP)</option>
                </select>
              </Row>
              <Row label="Priority">
                <select className="ve-input" value={attr('fetchpriority')} onChange={(event) => setAttr('fetchpriority', event.target.value === '' ? null : event.target.value)} aria-label="Fetch priority">
                  <option value="">auto</option>
                  <option value="high">high (LCP image)</option>
                  <option value="low">low</option>
                </select>
              </Row>
              <Row label="Width × height">
                <div className="ve-add ve-add--two">
                  <input className="ve-input" value={attr('width')} placeholder="width" onChange={(event) => setAttr('width', event.target.value)} aria-label="Width attribute" />
                  <input className="ve-input" value={attr('height')} placeholder="height" onChange={(event) => setAttr('height', event.target.value)} aria-label="Height attribute" />
                </div>
              </Row>
            </>
          )}
          {tag === 'a' && (
            <>
              <Row label="Title" touched={'title' in attrs}>
                <input className="ve-input" value={attr('title')} onChange={(event) => setAttr('title', event.target.value)} aria-label="Link title" />
              </Row>
              <Row label="rel">
                <input className="ve-input" value={attr('rel')} placeholder="nofollow noopener" list="ve-rel" onChange={(event) => setAttr('rel', event.target.value)} aria-label="rel" />
                <datalist id="ve-rel">
                  <option value="nofollow" />
                  <option value="nofollow noopener noreferrer" />
                  <option value="sponsored" />
                  <option value="ugc" />
                </datalist>
              </Row>
              <Row label="Target">
                <select className="ve-input" value={attr('target')} onChange={(event) => setAttr('target', event.target.value === '' ? null : event.target.value)} aria-label="Target">
                  <option value="">same tab</option>
                  <option value="_blank">new tab</option>
                </select>
              </Row>
            </>
          )}
          <Row label="aria-label" touched={'aria-label' in attrs}>
            <input className="ve-input" value={attr('aria-label')} onChange={(event) => setAttr('aria-label', event.target.value)} aria-label="aria-label" />
          </Row>
          <Row label="id (anchor)" touched={'id' in attrs}>
            <input className="ve-input" value={attr('id')} placeholder="section-name" onChange={(event) => setAttr('id', event.target.value)} aria-label="id" />
          </Row>
        </Section>
      )}

      <Section title={`Audit — ${errors} errors, ${warns} warnings`} count={errors + warns} defaultOpen>
        <ul className="ve-audit">
          {issues.map((issue, index) => (
            <li key={index} className={`ve-audit__${issue.level}`}>
              <button type="button" disabled={!issue.el} onClick={() => issue.el && select(issue.el)}>
                <span aria-hidden>{issue.level === 'ok' ? '✓' : issue.level === 'warn' ? '!' : '✕'}</span>
                {issue.text}
              </button>
            </li>
          ))}
        </ul>
      </Section>

      <Section title="Search engines (SEO)" defaultOpen>
        {template.templated && (
          <div className="ve-seg2" role="group" aria-label="What these SEO settings apply to">
            <button type="button" className={level === 'page' ? 'is-on' : ''} onClick={() => setLevel('page')}>
              This page
            </button>
            <button type="button" className={level === 'template' ? 'is-on' : ''} onClick={() => setLevel('template')}>
              Every {template.label.replace(/ template$/i, '')}
            </button>
          </div>
        )}
        {level === 'template' && template.templated && (
          <p className="ve-hint">
            Use <code>%title%</code> for the item’s own title and <code>%site%</code> for the site name, e.g. <code>%title% | %site%</code>.
            A page’s own settings win over the template’s.
          </p>
        )}
        <div className="ve-serp" aria-label="Search result preview">
          <div className="ve-serp__url">{new URL(frame?.win.location.href ?? 'http://x/').host}{page}</div>
          <div className="ve-serp__title">{pageTitle || 'Page title'}</div>
          <div className="ve-serp__desc">{pageDescription || 'The description Google shows under the title.'}</div>
        </div>

        <label className="ve-field">
          <span>SEO title {counter(seo.title ?? '', 30, 60)}</span>
          <input className="ve-input" value={seo.title ?? ''} placeholder={frame?.doc.title} onChange={(event) => setSeo({ title: event.target.value })} />
        </label>
        <label className="ve-field">
          <span>Meta description {counter(seo.description ?? '', 70, 160)}</span>
          <textarea className="ve-textarea" rows={3} value={seo.description ?? ''} onChange={(event) => setSeo({ description: event.target.value })} />
        </label>
        <label className="ve-field">
          <span>Share image URL</span>
          <input className="ve-input" value={seo.image ?? ''} onChange={(event) => setSeo({ image: event.target.value })} />
        </label>
        <label className="ve-field">
          <span>Canonical URL</span>
          <input className="ve-input" value={seo.canonical ?? ''} placeholder="Leave empty for automatic" onChange={(event) => setSeo({ canonical: event.target.value })} />
        </label>
        <div className="ve-checks">
          <label>
            <input type="checkbox" checked={Boolean(seo.noindex)} onChange={(event) => setSeo({ noindex: event.target.checked })} />
            Hide this page from search engines (noindex)
          </label>
          <label>
            <input type="checkbox" checked={Boolean(seo.nofollow)} onChange={(event) => setSeo({ nofollow: event.target.checked })} />
            Do not follow its links (nofollow)
          </label>
        </div>
        <label className="ve-field">
          <span>Structured data (JSON-LD) {jsonError && <em className="ve-counter is-warn">{jsonError}</em>}</span>
          <textarea
            className="ve-textarea ve-code"
            rows={6}
            spellCheck={false}
            value={seo.jsonld ?? ''}
            placeholder={'{\n  "@context": "https://schema.org",\n  "@type": "FAQPage"\n}'}
            onChange={(event) => setSeo({ jsonld: event.target.value })}
          />
        </label>
        <p className="ve-hint">Applies to this page only. Saved with the Save button.</p>
      </Section>
    </div>
  );
};
