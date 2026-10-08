import { useCallback, useEffect, useState } from 'react';

/** docked: in the editor · collapsed: a thin rail · window: shown in its own browser window. */
export type PanelState = 'docked' | 'collapsed' | 'window';
export type Side = 'left' | 'right';

export type Layout = { leftW: number; rightW: number; left: PanelState; right: PanelState };

export const MIN_WIDTH = 220;
export const MAX_WIDTH = 720;
export const RAIL = 34;

const KEY = 'sve-layout';
const DEFAULT: Layout = { leftW: 280, rightW: 340, left: 'docked', right: 'docked' };

const clamp = (value: number): number => Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, Math.round(value)));

const load = (): Layout => {
  try {
    const raw = JSON.parse(window.localStorage.getItem(KEY) ?? 'null') as Partial<Layout> | null;
    if (!raw) return DEFAULT;
    const state = (value: unknown): PanelState => (value === 'collapsed' ? 'collapsed' : 'docked'); // a window never survives a reload
    return {
      leftW: clamp(raw.leftW ?? DEFAULT.leftW),
      rightW: clamp(raw.rightW ?? DEFAULT.rightW),
      left: state(raw.left),
      right: state(raw.right),
    };
  } catch {
    return DEFAULT;
  }
};

/** Panel sizes and visibility, remembered between visits. */
export const useLayout = () => {
  const [layout, setLayout] = useState<Layout>(load);

  useEffect(() => {
    try {
      window.localStorage.setItem(KEY, JSON.stringify(layout));
    } catch {
      // Private mode: the layout simply is not remembered.
    }
  }, [layout]);

  const setState = useCallback(
    (side: Side, state: PanelState) => setLayout((current) => ({ ...current, [side]: state })),
    [],
  );
  const setWidth = useCallback(
    (side: Side, width: number) => setLayout((current) => ({ ...current, [side === 'left' ? 'leftW' : 'rightW']: clamp(width) })),
    [],
  );
  const reset = useCallback(() => setLayout(DEFAULT), []);

  return { layout, setState, setWidth, reset };
};

/** CSS grid column width of a side panel. */
export const columnWidth = (layout: Layout, side: Side): string => {
  const state = layout[side];
  return state === 'docked' ? `${side === 'left' ? layout.leftW : layout.rightW}px` : `${RAIL}px`;
};
