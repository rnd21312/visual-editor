import { useEffect, useRef, useState } from 'react';
import type { VisualDoc } from '@/lib/visualCss';

type Props = {
  canAdmin: boolean;
  access: VisualDoc['access'];
  onAccess: (access: 'admin' | 'editor') => void;
  onResetLayout: () => void;
};

const SHORTCUTS: [string, string][] = [
  ['Ctrl+S', 'Save'],
  ['Ctrl+Z / Ctrl+Shift+Z', 'Undo / redo'],
  ['Click · Ctrl+click', 'Select · add to selection'],
  ['Double-click text', 'Type on the page'],
  ['Right-click', 'Menu: copy, paste, group, delete…'],
  ['Ctrl+C / X / V', 'Copy / cut / paste the element'],
  ['Ctrl+D', 'Duplicate'],
  ['Ctrl+G / Ctrl+Shift+G', 'Group / ungroup'],
  ['Delete', 'Delete the element'],
  ['Esc', 'Deselect'],
  ['Drag ⠿ handle', 'Move · hold Alt to nudge freely'],
];

/** The gear menu: who may use the editor, panel layout and the shortcut cheat-sheet. */
export const SettingsPopover = ({ canAdmin, access, onAccess, onResetLayout }: Props) => {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const outside = (event: PointerEvent) => {
      if (!ref.current?.contains(event.target as Node)) setOpen(false);
    };
    window.addEventListener('pointerdown', outside);
    return () => window.removeEventListener('pointerdown', outside);
  }, [open]);

  return (
    <div className="ve-pop" ref={ref}>
      <button type="button" className="ve-icon-btn" title="Settings and shortcuts" aria-expanded={open} onClick={() => setOpen(!open)}>
        ⚙
      </button>
      {open && (
        <div className="ve-pop__body" role="dialog" aria-label="Editor settings">
          <h3>Who can use the editor</h3>
          <select
            className="ve-input"
            value={access ?? 'admin'}
            disabled={!canAdmin}
            onChange={(event) => onAccess(event.target.value as 'admin' | 'editor')}
            aria-label="Who can use the editor"
          >
            <option value="admin">Administrators only</option>
            <option value="editor">Administrators and editors</option>
          </select>
          {!canAdmin && <p className="ve-hint">Only an administrator can change this. Saved with the Save button.</p>}

          <h3>Panels</h3>
          <button type="button" className="ve-btn" onClick={onResetLayout}>
            Reset panel sizes and positions
          </button>

          <h3>Shortcuts</h3>
          <dl className="ve-keys">
            {SHORTCUTS.map(([keys, text]) => (
              <div key={keys}>
                <dt>{keys}</dt>
                <dd>{text}</dd>
              </div>
            ))}
          </dl>
        </div>
      )}
    </div>
  );
};
