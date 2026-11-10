import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

/** Shared by forum.js and the lazy chunk: just the translator shortcuts. */

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-hype-market.forum.${key}`, params);
export const tx = (key: string, params: Record<string, any> = {}) => extractText(t(key, params));

/** "+84%" / "−12%" / "0%". */
export function pct(change: number): string {
  const value = Math.round(change * 100);
  return (value > 0 ? '+' : value < 0 ? '−' : '') + Math.abs(value).toLocaleString() + '%';
}
