// Compiles React island source (JSX / TypeScript) into an ES module the browser can load directly:
// JSX → React.createElement (automatic runtime), bare imports → esm.sh URLs sharing one React.

export const resolveImports = (code: string, react: string): string => {
  const map: Record<string, string> = {
    react: `https://esm.sh/react@${react}`,
    'react/jsx-runtime': `https://esm.sh/react@${react}/jsx-runtime`,
    'react-dom': `https://esm.sh/react-dom@${react}`,
    'react-dom/client': `https://esm.sh/react-dom@${react}/client`,
  };
  return code.replace(/(\bfrom\s*|\bimport\s*\(\s*|\bimport\s+)(["'])([^"'\s]+)\2/g, (m, pre: string, q: string, spec: string) => {
    if (/^(https?:|\/|\.\/|\.\.\/|data:)/.test(spec)) return m;
    const url = map[spec] ?? `https://esm.sh/${spec}${spec.includes('?') ? '&' : '?'}deps=react@${react},react-dom@${react}`;
    return pre + q + url + q;
  });
};

export const compileJsx = async (source: string, react: string): Promise<{ code: string; imports: string[] }> => {
  const { transform } = await import('sucrase');
  const out = transform(source, { transforms: ['jsx', 'typescript'], jsxRuntime: 'automatic', production: true, filePath: 'island.tsx' });
  const imports = Array.from(out.code.matchAll(/\bfrom\s*["']([^"'./][^"']*)["']/g)).map((m) => m[1]);
  return { code: resolveImports(out.code, react), imports: Array.from(new Set(imports)) };
};
