// CodeMirror 6 (loaded only when a code field is opened).
import { useEffect, useRef } from 'react';
import { EditorView, basicSetup } from 'codemirror';
import { EditorState } from '@codemirror/state';
import { keymap } from '@codemirror/view';
import { indentWithTab } from '@codemirror/commands';
import { html } from '@codemirror/lang-html';
import { css } from '@codemirror/lang-css';
import { javascript } from '@codemirror/lang-javascript';
import { oneDark } from '@codemirror/theme-one-dark';

const language = (lang: string) => (lang === 'css' ? css() : lang === 'html' ? html({ autoCloseTags: true }) : javascript({ jsx: lang === 'jsx', typescript: false }));

export default function CodeEditor({ value, onChange, lang, height }: { value: string; onChange: (v: string) => void; lang: 'html' | 'css' | 'js' | 'jsx'; height: number }) {
  const host = useRef<HTMLDivElement>(null);
  const view = useRef<EditorView | null>(null);
  const change = useRef(onChange);
  change.current = onChange;

  useEffect(() => {
    if (!host.current) return;
    view.current = new EditorView({
      parent: host.current,
      state: EditorState.create({
        doc: value,
        extensions: [
          basicSetup,
          keymap.of([indentWithTab]),
          language(lang),
          oneDark,
          EditorView.lineWrapping,
          EditorView.theme({ '&': { height: height + 'px' }, '.cm-scroller': { fontFamily: 'ui-monospace, Consolas, monospace' } }),
          EditorView.updateListener.of((u) => {
            if (u.docChanged) change.current(u.state.doc.toString());
          }),
        ],
      }),
    });
    return () => view.current?.destroy();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lang]);

  useEffect(() => {
    const v = view.current;
    if (v && v.state.doc.toString() !== value) v.dispatch({ changes: { from: 0, to: v.state.doc.length, insert: value } });
  }, [value]);

  return <div className="ve-cm" ref={host} dir="ltr" />;
}
