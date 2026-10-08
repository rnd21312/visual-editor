import { describe, expect, it } from 'vitest';
import { emptyDoc } from '@/lib/visualCss';
import { blankEdit, initHistory, reducer, type Action, type History } from './state';

const target = { sel: 'h1', label: 'h1', scope: 'page' as const, page: '/' };

const setColor = (id: string, fallback: ReturnType<typeof blankEdit>, value: string, at: number): Action => ({
  type: 'edit',
  id,
  fallback,
  key: 'color',
  at,
  patch: (edit) => {
    const base: Record<string, string> = value ? { color: value } : {};
    return { ...edit, styles: { base } };
  },
});

const run = (history: History, ...actions: Action[]): History => actions.reduce(reducer, history);

describe('visual editor history', () => {
  it('creates an edit on first change and merges quick keystrokes into one undo step', () => {
    const fallback = blankEdit(target);
    const start = initHistory(emptyDoc());
    const after = run(start, setColor(fallback.id, fallback, 'r', 1000), setColor(fallback.id, fallback, 're', 1200));

    expect(after.present.edits).toHaveLength(1);
    expect(after.present.edits[0]?.styles.base?.color).toBe('re');
    expect(after.past).toHaveLength(1);
  });

  it('starts a new undo step after a pause, and undo / redo walk the steps', () => {
    const fallback = blankEdit(target);
    let history = run(initHistory(emptyDoc()), setColor(fallback.id, fallback, 'red', 1000));
    history = run(history, setColor(fallback.id, fallback, 'blue', 5000));
    expect(history.past).toHaveLength(2);

    history = run(history, { type: 'undo' });
    expect(history.present.edits[0]?.styles.base?.color).toBe('red');
    history = run(history, { type: 'redo' });
    expect(history.present.edits[0]?.styles.base?.color).toBe('blue');
  });

  it('drops an edit once nothing is left in it', () => {
    const fallback = blankEdit(target);
    const history = run(
      initHistory(emptyDoc()),
      setColor(fallback.id, fallback, 'red', 1000),
      setColor(fallback.id, fallback, '', 9000),
    );
    expect(history.present.edits).toHaveLength(0);
  });

  it('knows when the document differs from the saved one', () => {
    const fallback = blankEdit(target);
    const changed = run(initHistory(emptyDoc()), setColor(fallback.id, fallback, 'red', 1));
    expect(changed.present).not.toBe(changed.saved);

    const saved = run(changed, { type: 'saved', doc: changed.present });
    expect(saved.present).toBe(saved.saved);

    const undone = run(saved, { type: 'undo' });
    expect(undone.present).not.toBe(undone.saved);
  });
});
