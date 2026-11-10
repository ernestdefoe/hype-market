import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import stringToColor from 'flarum/common/utils/stringToColor';
import { tx } from '../util';

/*
 * Talking to the server, the types and the formatting: only the lazy chunk
 * needs these, so they stay out of forum.js.
 */

export interface Person {
  id: number;
  username: string;
  displayName: string;
  slug: string;
  avatarUrl: string | null;
}

export interface Mine {
  id: number;
  stake: number;
  /** Percent of the stake this call loses if the discussion stays flat (fixed when it was made). */
  loss: number;
  entryPrice: number;
  createdAt: string;
  settledAt: string | null;
  exitPrice: number | null;
  multiplier: number;
  /** What it pays (settled) or would pay at today's price (open). */
  value: number;
  profit: number;
}

export interface Market {
  id: number;
  discussion: { id: number; title: string; slug: string; commentCount: number; createdAt: string | null } | null;
  author: Person | null;
  status: 'open' | 'closed' | 'settled' | 'void';
  openedAt: string;
  closesAt: string;
  settlesAt: string;
  price: number;
  change: number;
  momentum: number;
  participants: number;
  participantsNeeded: number;
  backers: number;
  staked: number;
  history: Array<[number, number]>;
  canBack: boolean;
  mine: Mine | null;
}

export interface Wallet {
  allowance: number;
  spent: number;
  left: number;
  backs: number;
  maxBacks: number;
  minStake: number;
  maxStake: number;
  resetsAt: string;
  canBack: boolean;
}

export interface Season {
  id: number;
  name: string;
  startedAt: string;
  endedAt: string | null;
}

export interface BoardRow {
  rank: number;
  user: Person;
  profit: number;
  staked: number;
  settled: number;
  wins: number;
  roi: number;
  bestMultiplier: number;
}

export interface Board {
  season: Season;
  currentSeasonId: number;
  seasons: Season[];
  rows: BoardRow[];
  me: BoardRow | null;
}

export interface Portfolio {
  open: Market[];
  settled: Market[];
  wallet: Wallet;
  season: { season: Season; profit: number; settled: number; wins: number; bestMultiplier: number; rank: number | null; atStake: number };
}

/** The sentence for an error answer from our endpoints. */
export function errorText(error: any): string {
  const code = error?.response?.errors?.[0]?.code;
  const key = `ernestdefoe-hype-market.forum.errors.${code}`;
  if (code && extractText(app.translator.trans(key)) !== key) return extractText(app.translator.trans(key));
  if (error?.status === 0) return extractText(app.translator.trans('core.lib.error.network_message'));
  if (error?.status === 403) return extractText(app.translator.trans('core.lib.error.permission_denied_message'));
  return tx('errors.generic');
}

export function call<T>(method: 'GET' | 'POST', path: string, data?: Record<string, any>, quiet = false): Promise<T> {
  const url = app.forum.attribute<string>('apiUrl') + '/hype' + path;
  const query = method === 'GET' && data ? '?' + new URLSearchParams(Object.entries(data).map(([k, v]) => [k, String(v)])).toString() : '';
  return app.request<T>({
    method,
    url: url + query,
    body: method === 'GET' ? undefined : data,
    errorHandler: (error: any) => {
      if (!quiet) app.alerts.show({ type: 'error' }, errorText(error));
      throw error;
    },
  });
}

export const num = (n: number) => Math.round(n).toLocaleString();
export const price = (n: number) => n.toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
export const signed = (n: number) => (n > 0 ? '+' : n < 0 ? '−' : '') + Math.abs(Math.round(n)).toLocaleString();
export const times = (n: number) => n.toLocaleString(undefined, { maximumFractionDigits: 2 }) + '×';

/** "2d 3h", "9h 12m", "4m": the time until an ISO date (never negative). */
export function until(iso: string): string {
  const minutes = Math.max(0, Math.round((new Date(iso).getTime() - Date.now()) / 60000));
  const d = Math.floor(minutes / 1440);
  const h = Math.floor((minutes % 1440) / 60);
  const m = minutes % 60;
  if (d) return tx('time.dh', { d, h });
  if (h) return tx('time.hm', { h, m });
  return tx('time.m', { m: Math.max(1, m) });
}

/** The forum's current loss share for new calls, in percent (0 = nobody loses). */
export const lossPercent = (): number => Number(app.forum.attribute('hypeLoss') || 0);

/** "up", "down" or "flat": the colour a change is shown in. */
export const trend = (change: number) => (change > 0.005 ? 'up' : change < -0.005 ? 'down' : 'flat');

/** Avatar takes a User model; ours is plain JSON, so it gets the few methods Avatar calls. */
export function fakeUser(p: Person): any {
  return {
    displayName: () => p.displayName,
    username: () => p.username,
    avatarUrl: () => p.avatarUrl,
    color: () => '#' + stringToColor(p.username),
    id: () => String(p.id),
  };
}

export function discussionUrl(market: Market): string | null {
  const d = market.discussion;
  return d ? app.route('discussion', { id: d.slug || String(d.id) }) : null;
}
