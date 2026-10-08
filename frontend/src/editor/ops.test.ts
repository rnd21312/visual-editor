import { describe, expect, it } from 'vitest';
import { emptyDoc, opsForPage, resolveOps, type Edit } from '@/lib/visualCss';
import { cleanHtml, makeInsertOp, makeMoveOp, rebaseEdits, withOp } from './ops';

const edit = (sel: string): Edit => ({ id: sel, scope: 'page', page: '/', sel, label: sel, styles: {}, css: '' });

describe('rebaseEdits', () => {
  it('moves an element’s edits, and its descendants’, onto its new id', () => {
    const edits = [edit('#a > div:nth-child(1)'), edit('#a > div:nth-child(1) > h2'), edit('#a > div:nth-child(2)')];
    const result = rebaseEdits(edits, '#a > div:nth-child(1)', '[data-ve-id="x"]');

    expect(result.map((e) => e.sel)).toEqual([
      '[data-ve-id="x"]',
      '[data-ve-id="x"] > h2',
      '#a > div:nth-child(2)',
    ]);
  });
});

describe('ops', () => {
  it('appends ops in order and rebases edits in the same step', () => {
    const doc = { ...emptyDoc(), edits: [edit('#a > p')] };
    const move = makeMoveOp('#a > p', '#b', 'append', 'p', 'page', '/');
    const next = withOp(doc, move, { from: '#a > p', to: `[data-ve-id="${move.id}"]` });

    expect(next.ops).toHaveLength(1);
    expect(next.edits[0]?.sel).toBe(`[data-ve-id="${move.id}"]`);
  });

  it('keeps site-wide ops and the ones of the page, and resolves component markup', () => {
    const home = makeInsertOp('#a', 'after', '<p>old</p>', 'Hero', 'page', '/', 'c1');
    const tours = makeInsertOp('#a', 'after', '<p>x</p>', 'Other', 'page', '/tours/');
    const site = makeInsertOp('footer', 'append', '<i>y</i>', 'Footer', 'site', '/tours/');
    const doc = {
      ...emptyDoc(),
      ops: [home, tours, site],
      components: [{ id: 'c1', name: 'Hero', html: '<p>new</p>', css: '' }],
    };

    expect(opsForPage(doc, '/').map((op) => op.id)).toEqual([home.id, site.id]);
    const resolved = resolveOps(doc, '/').find((op) => op.id === home.id);
    expect(resolved && resolved.kind === 'insert' && resolved.html).toBe('<p>new</p>');
  });
});

describe('cleanHtml', () => {
  it('drops the editor’s own attributes', () => {
    expect(cleanHtml('<div data-ve-id="a" data-ve-ops="a" contenteditable="true" class="x">t</div>')).toBe(
      '<div class="x">t</div>',
    );
  });
});

describe('embed components', () => {
  it('marks inserts of an embed component so their scripts run', () => {
    const embed = makeInsertOp('#a', 'after', '<script></script>', 'Widget', 'page', '/', 'e1');
    const plain = makeInsertOp('#a', 'after', '<p></p>', 'Text', 'page', '/', 'h1');
    const doc = {
      ...emptyDoc(),
      ops: [embed, plain],
      components: [
        { id: 'e1', name: 'Widget', html: '<div>widget</div>', css: '', kind: 'embed' as const },
        { id: 'h1', name: 'Text', html: '<p>text</p>', css: '' },
      ],
    };

    const [first, second] = resolveOps(doc, '/');
    expect(first && first.kind === 'insert' && first.exec).toBe(true);
    expect(second && second.kind === 'insert' && second.exec).toBeUndefined();
  });

  it('lets a custom embed insert carry the exec flag itself', () => {
    const op = makeInsertOp('#a', 'append', '<iframe></iframe>', 'Embed', 'page', '/', undefined, true);
    expect(op.kind === 'insert' && op.exec).toBe(true);
  });
});
