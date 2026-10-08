import { useEditor } from '../context';
import { setStyleProp } from '../state';

/** The properties edited for the current device + state, and the setter the style controls share. */
export const useStyles = () => {
  const { activeEdit, target, device, state, patch, frame, selected } = useEditor();

  const styles =
    (state === 'normal' ? activeEdit?.styles[device] : activeEdit?.states?.[state]?.[device]) ?? {};

  const set = (prop: string, value: string) =>
    patch(
      (edit) => setStyleProp(edit, device, state, prop, value),
      `style:${target?.sel ?? ''}:${device}:${state}:${prop}`,
    );

  /** What the browser resolved for the selected element right now. */
  const computedStyle = frame && selected ? frame.win.getComputedStyle(selected) : null;
  const computed = (prop: string): string => computedStyle?.getPropertyValue(prop).trim() ?? '';

  return { styles, set, computed, computedStyle };
};
