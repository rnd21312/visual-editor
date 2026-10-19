// UI language: English source strings, translated when the user's WordPress language has a dictionary.
let dict: Record<string, string> = {};
let rtl = false;

/** The Persian dictionary (34 KB) is only fetched for Persian users. */
export const setLanguage = async (locale: string): Promise<void> => {
  const lang = locale.toLowerCase();
  dict = lang.startsWith('fa') ? (await import('./i18n-fa')).fa : {};
  rtl = lang.startsWith('fa') || lang.startsWith('ar') || lang.startsWith('he');
};

export const isRtl = (): boolean => rtl;

/** Translates a UI string; {name} placeholders are filled from vars. */
export const t = (text: string, vars?: Record<string, string | number>): string => {
  let out = dict[text] ?? text;
  if (vars) for (const [k, v] of Object.entries(vars)) out = out.split(`{${k}}`).join(String(v));
  return out;
};
