import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Avatar from 'flarum/common/components/Avatar';
import Link from 'flarum/common/components/Link';
import Select from 'flarum/common/components/Select';
import type Mithril from 'mithril';
import MarketCard from './MarketCard';
import {
  call,
  fakeUser,
  lossPercent,
  num,
  signed,
  times,
  trend,
  until,
  type Board,
  type BoardRow,
  type Market,
  type Portfolio,
  type Wallet,
} from './data';
import { t, tx } from '../util';

/**
 * /hype: the hottest markets right now, the member's portfolio and the season
 * leaderboard. Built in core's page structure with the forum's own sidebar,
 * so any theme dresses it.
 */

declare const m: Mithril.Static;
declare const flarum: any;

type Tab = 'hot' | 'portfolio' | 'leaderboard';
type Sort = 'hot' | 'new' | 'top' | 'closing';
const SORTS: Sort[] = ['hot', 'new', 'top', 'closing'];

/** Core components this page builds on, loaded through the registry: some live in lazy chunks. */
let PageStructure: any;
let IndexSidebar: any;

export async function loadCore(): Promise<void> {
  const [structure, sidebar] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  PageStructure = structure.default || structure;
  IndexSidebar = sidebar.default || sidebar;
}

export default class HypePage extends Page {
  tab: Tab = 'hot';
  sort: Sort = 'hot';
  markets: Market[] | null = null;
  wallet: Wallet | null = null;
  portfolio: Portfolio | null = null;
  board: Board | null = null;
  boardSeason = '';
  private ticket = 0;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    const tab = m.route.param('tab') as Tab;
    if (tab === 'leaderboard' || (tab === 'portfolio' && app.session.user)) this.tab = tab;
    app.history.push('hype', tx('page.title'));
    this.bodyClass = 'App--hype';
    this.load();
    if (app.session.user) this.loadPortfolio();
  }

  oncreate(vnode: Mithril.VnodeDOM) {
    super.oncreate(vnode);
    app.setTitle(tx('page.title'));
    app.setTitleCount(0);
  }

  load() {
    if (this.tab === 'hot') this.loadMarkets();
    if (this.tab === 'leaderboard') this.loadBoard();
    if (this.tab === 'portfolio') this.loadPortfolio();
  }

  loadMarkets() {
    const ticket = ++this.ticket;
    this.markets = null;
    call<{ markets: Market[]; wallet: Wallet | null }>('GET', '/markets', { sort: this.sort }).then((res) => {
      if (ticket !== this.ticket) return;
      this.markets = res.markets;
      this.wallet = res.wallet;
      m.redraw();
    });
  }

  loadPortfolio() {
    call<Portfolio>('GET', '/portfolio').then((res) => {
      this.portfolio = res;
      this.wallet = res.wallet;
      m.redraw();
    });
  }

  loadBoard() {
    this.board = null;
    call<Board>('GET', '/leaderboard', this.boardSeason ? { season: this.boardSeason } : undefined).then((res) => {
      this.board = res;
      m.redraw();
    });
  }

  /** A back from any card: update it everywhere it shows, and the wallet. */
  backed(market: Market, wallet: Wallet) {
    this.wallet = wallet;
    this.markets = this.markets?.map((x) => (x.id === market.id ? market : x)) ?? null;
    if (app.session.user) this.loadPortfolio();
    m.redraw();
  }

  switchTab(tab: Tab) {
    if (tab === this.tab) return;
    this.tab = tab;
    window.history.replaceState(window.history.state, '', app.route('hype') + (tab === 'hot' ? '' : '?tab=' + tab));
    this.load();
  }

  view() {
    const tabs: Tab[] = app.session.user ? ['hot', 'portfolio', 'leaderboard'] : ['hot', 'leaderboard'];

    return (
      <PageStructure className="HypePage" sidebar={() => <IndexSidebar />}>
        <header className="HypePage-hero">
          <div className="HypePage-heading">
            <h2 className="HypePage-title">
              <span className="HypePage-flame">
                <Icon name="fas fa-fire" />
              </span>
              {t('page.title')}
            </h2>
            <p className="HypePage-lead">{t('page.lead')}</p>
          </div>
          {this.walletStrip()}
        </header>

        <nav className="HypePage-tabs" role="tablist" aria-label={tx('page.title')}>
          {tabs.map((tab) => (
            <button
              type="button"
              role="tab"
              aria-selected={this.tab === tab ? 'true' : 'false'}
              className={'HypePage-tab' + (this.tab === tab ? ' is-active' : '')}
              onclick={() => this.switchTab(tab)}
            >
              <Icon name={{ hot: 'fas fa-fire-flame-curved', portfolio: 'fas fa-briefcase', leaderboard: 'fas fa-trophy' }[tab]} />{' '}
              {t(`page.tabs.${tab}`)}
            </button>
          ))}
        </nav>

        {this.tab === 'hot' ? this.hotView() : this.tab === 'portfolio' ? this.portfolioView() : this.boardView()}
      </PageStructure>
    );
  }

  walletStrip() {
    if (!app.session.user) {
      return (
        <div className="HypeWallet HypeWallet--guest">
          <p>{t('wallet.guest')}</p>
          <Button className="Button Button--primary" onclick={() => app.modal.show(() => import('flarum/forum/components/LogInModal'))}>
            {t('wallet.log_in')}
          </Button>
        </div>
      );
    }
    const w = this.wallet;
    const s = this.portfolio?.season;
    return (
      <div className="HypeWallet">
        <div className="HypeWallet-tile HypeWallet-tile--hype">
          <span className="HypeWallet-label">{t('wallet.left')}</span>
          <span className="HypeWallet-value">
            <Icon name="fas fa-fire" /> {w ? num(w.left) : '–'}
            <small>/ {w ? num(w.allowance) : '–'}</small>
          </span>
          <span className="HypeWallet-bar" aria-hidden="true">
            <span style={{ width: w ? `${Math.round((w.left / w.allowance) * 100)}%` : '0%' }} />
          </span>
          <span className="HypeWallet-sub">{w ? t('wallet.refills', { time: until(w.resetsAt) }) : ''}</span>
        </div>
        <div className="HypeWallet-tile">
          <span className="HypeWallet-label">{t('wallet.profit')}</span>
          <span className={'HypeWallet-value HypeTrend--' + (s ? trend(s.profit) : 'flat')}>{s ? signed(s.profit) : '–'}</span>
          <span className="HypeWallet-sub">{s?.rank ? t('wallet.rank', { rank: s.rank, season: s.season.name }) : s ? s.season.name : ''}</span>
        </div>
        <div className="HypeWallet-tile">
          <span className="HypeWallet-label">{t('wallet.open')}</span>
          <span className="HypeWallet-value">{this.portfolio ? num(this.portfolio.open.length) : '–'}</span>
          <span className="HypeWallet-sub">{s ? t('wallet.at_stake', { n: num(s.atStake) }) : ''}</span>
        </div>
      </div>
    );
  }

  hotView() {
    return (
      <div className="HypePage-hot">
        <div className="HypePage-sorts" role="group" aria-label={tx('page.sort_label')}>
          {SORTS.map((sort) => (
            <button
              type="button"
              className={'HypeChipButton' + (this.sort === sort ? ' is-active' : '')}
              aria-pressed={this.sort === sort ? 'true' : 'false'}
              onclick={() => {
                this.sort = sort;
                this.loadMarkets();
              }}
            >
              {t(`page.sorts.${sort}`)}
            </button>
          ))}
        </div>
        {this.markets === null ? (
          <LoadingIndicator />
        ) : this.markets.length ? (
          <div className="HypePage-list">
            {this.markets.map((market, i) => (
              <MarketCard
                key={market.id}
                market={market}
                wallet={this.wallet}
                rank={this.sort === 'hot' ? i + 1 : undefined}
                onback={(mk, w) => this.backed(mk, w)}
              />
            ))}
          </div>
        ) : (
          this.empty('fas fa-seedling', 'page.empty_hot')
        )}
      </div>
    );
  }

  portfolioView() {
    const p = this.portfolio;
    if (!p) return <LoadingIndicator />;
    return (
      <div className="HypePage-portfolio">
        <h3 className="HypePage-section">{t('portfolio.open', { count: p.open.length })}</h3>
        {p.open.length ? (
          <div className="HypePage-list">
            {p.open.map((market) => (
              <MarketCard key={market.id} market={market} wallet={this.wallet} onback={(mk, w) => this.backed(mk, w)} />
            ))}
          </div>
        ) : (
          this.empty('fas fa-briefcase', 'portfolio.empty_open')
        )}

        <h3 className="HypePage-section">{t('portfolio.settled')}</h3>
        {p.settled.length ? (
          <ul className="HypeHistory">
            {p.settled.map((market) => {
              const mine = market.mine!;
              return (
                <li className={'HypeHistory-row HypeTrend--' + trend(mine.profit)}>
                  <span className="HypeHistory-title">
                    {market.discussion ? (
                      <Link href={app.route('discussion', { id: market.discussion.slug })}>{market.discussion.title}</Link>
                    ) : (
                      t('card.gone')
                    )}
                  </span>
                  <span className="HypeHistory-stake">{t('portfolio.staked', { n: num(mine.stake) })}</span>
                  <span className="HypeHistory-x">{market.status === 'void' ? t('portfolio.void') : times(mine.multiplier)}</span>
                  <strong className="HypeHistory-profit">{signed(mine.profit)}</strong>
                </li>
              );
            })}
          </ul>
        ) : (
          <p className="HypePage-quiet">{t('portfolio.empty_settled')}</p>
        )}
      </div>
    );
  }

  boardView() {
    const board = this.board;
    if (!board) return <LoadingIndicator />;
    return (
      <div className="HypeBoard">
        <div className="HypeBoard-toolbar">
          <Select
            aria-label={tx('board.season')}
            value={this.boardSeason || String(board.season.id)}
            options={Object.fromEntries(board.seasons.map((s) => [String(s.id), s.endedAt ? s.name : tx('board.current', { name: s.name })]))}
            onchange={(value: string) => {
              this.boardSeason = value;
              this.loadBoard();
            }}
          />
          <details className="HypeBoard-how">
            <summary>{t('board.how')}</summary>
            <ul>
              <li>{t('board.how_price')}</li>
              <li>{t('board.how_pay')}</li>
              {lossPercent() ? <li>{t('board.how_loss', { percent: lossPercent(), x: 1 + lossPercent() / 100 })}</li> : null}
              <li>{t('board.how_fair')}</li>
              <li>{t('board.how_rank')}</li>
            </ul>
          </details>
        </div>
        {board.rows.length ? (
          <ol className="HypeBoard-rows">
            {board.rows.map((row) => this.boardRow(row, row.user.id === board.me?.user.id))}
            {board.me &&
              !board.rows.some((r) => r.user.id === board.me!.user.id) && [
                <li className="HypeBoard-gap" aria-hidden="true">
                  ⋯
                </li>,
                this.boardRow(board.me, true),
              ]}
          </ol>
        ) : (
          this.empty('fas fa-trophy', 'board.empty')
        )}
      </div>
    );
  }

  boardRow(row: BoardRow, isMe: boolean) {
    return (
      <li className={'HypeBoard-row' + (row.rank <= 3 ? ` is-top is-top-${row.rank}` : '') + (isMe ? ' is-me' : '')}>
        <span className="HypeBoard-rank">{row.rank <= 3 ? <Icon name="fas fa-medal" /> : row.rank}</span>
        <Link className="HypeBoard-member" href={app.route('user', { username: row.user.slug })}>
          <Avatar user={fakeUser(row.user)} className="HypeAvatar HypeAvatar--board" />
          <span className="HypeBoard-name">{row.user.displayName}</span>
        </Link>
        <span className="HypeBoard-record">{t('board.record', { wins: row.wins, settled: row.settled })}</span>
        <span className="HypeBoard-best" title={tx('board.best_title')}>
          {row.bestMultiplier > 1 ? times(row.bestMultiplier) : '–'}
        </span>
        <strong className={'HypeBoard-profit HypeTrend--' + trend(row.profit)}>{signed(row.profit)}</strong>
      </li>
    );
  }

  empty(icon: string, key: string) {
    return (
      <div className="HypePage-empty">
        <Icon name={icon} />
        <p>{t(key)}</p>
      </div>
    );
  }
}
