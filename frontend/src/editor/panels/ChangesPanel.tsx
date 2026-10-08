import { DEVICES, editsForPage, hasContent, opsForPage, STATES, type Edit, type Op } from '@/lib/visualCss';
import { useEditor } from '../context';

const summary = (edit: Edit): string => {
  const parts: string[] = [];
  const props = DEVICES.reduce(
    (sum, device) =>
      sum +
      Object.keys(edit.styles[device] ?? {}).length +
      STATES.reduce((inner, state) => inner + Object.keys(edit.states?.[state]?.[device] ?? {}).length, 0),
    0,
  );
  if (props > 0) parts.push(`${props} style${props === 1 ? '' : 's'}`);
  if (hasContent(edit)) parts.push('content');
  if (edit.css.trim()) parts.push('CSS');
  if (Object.values(edit.hide ?? {}).some(Boolean)) parts.push('visibility');
  return parts.join(' · ');
};

/** Every change that applies to this page (page-scoped and site-wide), with a way to jump to or drop each. */
export const ChangesPanel = () => {
  const { doc, page, template, frame, select, setActiveId, removeEdit, patchDoc } = useEditor();
  const edits = editsForPage(doc, page, template.key);
  const ops = opsForPage(doc, page, template.key);

  const jump = (edit: Edit) => {
    let el: HTMLElement | null = null;
    try {
      el = frame?.doc.querySelector<HTMLElement>(edit.sel) ?? null;
    } catch {
      el = null;
    }
    // select() resets the active rule, so pick the rule afterwards.
    if (el) select(el);
    setActiveId(edit.id);
  };

  const removeOp = (op: Op) =>
    patchDoc((current) => ({ ...current, ops: (current.ops ?? []).filter((item) => item.id !== op.id) }), '');

  if (edits.length === 0 && ops.length === 0) {
    return (
      <p className="ve-note">
        No changes on this page yet. Click anything on the page, then change it on the right.
      </p>
    );
  }

  return (
    <div className="ve-changes">
      {ops.map((op) => (
        <div key={op.id} className="ve-change">
          <div className="ve-change__main">
            <span className="ve-change__label">{op.label || op.sel}</span>
            <span className="ve-change__meta">
              {op.kind === 'insert' ? 'added' : op.kind === 'move' ? 'moved' : op.kind === 'remove' ? 'deleted' : 'tag changed'} ·{' '}
              {op.scope === 'site' ? 'whole site' : 'this page'}
            </span>
          </div>
          <button
            type="button"
            className="ve-icon-btn"
            aria-label={`Undo ${op.label || op.kind}`}
            title="Undo this change (the page reloads)"
            onClick={() => removeOp(op)}
          >
            ×
          </button>
        </div>
      ))}
      {edits.map((edit) => (
        <div key={edit.id} className="ve-change">
          <button type="button" className="ve-change__main" onClick={() => jump(edit)}>
            <span className="ve-change__label">{edit.label || edit.sel}</span>
            <span className="ve-change__meta">
              {summary(edit)} · {edit.scope === 'site' ? 'whole site' : 'this page'}
            </span>
          </button>
          <button
            type="button"
            className="ve-icon-btn"
            aria-label={`Remove changes to ${edit.label || edit.sel}`}
            title="Remove"
            onClick={() => removeEdit(edit.id)}
          >
            ×
          </button>
        </div>
      ))}
      <button
        type="button"
        className="ve-btn ve-btn--ghost"
        onClick={() =>
          patchDoc((current) => ({ ...current, edits: current.edits.filter((edit) => !edits.includes(edit)) }), '')
        }
      >
        Remove all changes shown
      </button>
    </div>
  );
};
