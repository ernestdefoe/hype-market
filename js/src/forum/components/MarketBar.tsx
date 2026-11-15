import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import type Mithril from 'mithril';
import Sparkline from './Sparkline';
import { openBack } from './MarketCard';
import { call, clockText, num, price, signed, trend, trendIcon, type Market, type Wallet } from './data';
import { pct, t, tx } from '../util';

interface Attrs {
  discussionId: number;
  host: Element;
}

/**
 * The market under a discussion's first post: its price, change and
 * sparkline, who's in, the clock and "Back it" (or your own position).
 *
 * Its own Mithril root (m.mount): the post's subtree retainer skips redraws
 * until the POST changes, so a child would never show its data. It unmounts
 * itself once the post has replaced the element it lives in.
 */
class Bar extends Component<Attrs> {
  market: Market | null = null;
  wallet: Wallet | null = null;
  canModerate = false;
  loaded = false;
  /** The bar couldn't load: it says so, and the post reads as normal. */
  failed = false;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    call<{ market: Market | null; wallet: Wallet | null; canModerate: boolean }>('GET', `/discussions/${this.attrs.discussionId}`, undefined, true)
      .then((res) => {
        this.market = res.market;
        this.wallet = res.wallet;
        this.canModerate = res.canModerate;
      })
      .catch(() => (this.failed = true))
      .finally(() => {
        this.loaded = true;
        m.redraw();
      });
  }

  view() {
    if (!this.attrs.host.isConnected) {
      window.setTimeout(() => m.mount(this.attrs.host, null), 0);
      return null;
    }
    if (this.failed) return this.failedView();
    const market = this.market;
    if (!market) return null;

    const settled = market.status === 'settled' || market.status === 'void';
    const direction = trend(market.change);
    const mine = market.mine;

    return (
      <aside className={`HypeBar HypeTrend--${direction}`} aria-label={tx('bar.label')}>
        <Link className="HypeBar-brand" href={app.route('hype')}>
          <Icon name="fas fa-fire" />
          <span>{t('bar.brand')}</span>
        </Link>
        <div className="HypeBar-quote">
          <strong className="HypeBar-price">{price(market.price)}</strong>
          <span className="HypeBar-change">
            <Icon name={trendIcon(direction)} /> {pct(market.change)}
          </span>
        </div>
        <Sparkline
          points={market.history}
          entry={mine ? { at: Date.parse(mine.createdAt) / 1000, price: mine.entryPrice } : null}
          className="HypeBar-spark"
        />
        <div className="HypeBar-facts">
          <span>
            <Icon name="fas fa-users" /> {t('card.backers', { count: market.backers })}
          </span>
          <span>
            <Icon name="far fa-clock" /> {clockText(market)}
          </span>
        </div>
        <div className="HypeBar-action">
          {this.action(market, settled)}
          {this.canModerate && !settled ? (
            <Button className="Button Button--link HypeBar-void" onclick={() => this.callOff()}>
              {t('bar.call_off')}
            </Button>
          ) : null}
        </div>
      </aside>
    );
  }

  /** The bar couldn't load: one quiet line instead of the market. */
  failedView() {
    return (
      <aside className="HypeBar HypeBar--failed" aria-label={tx('bar.label')}>
        <Icon name="fas fa-fire" /> {t('bar.failed')}
      </aside>
    );
  }

  /** The member's own position, a note for the author, or Back it. */
  action(market: Market, settled: boolean) {
    const mine = market.mine;
    if (mine) {
      return (
        <span className={'HypeMine HypeTrend--' + trend(mine.profit)}>
          <span className="HypeMine-label">{t('card.you_backed', { n: num(mine.stake), price: price(mine.entryPrice) })}</span>
          <strong className="HypeMine-value">{t(settled ? 'bar.paid' : 'bar.worth', { n: num(mine.value), profit: signed(mine.profit) })}</strong>
        </span>
      );
    }
    if (app.session.user && market.author?.id === Number(app.session.user.id())) {
      return <span className="HypeBar-note">{market.status === 'open' ? t('bar.yours') : null}</span>;
    }
    if (market.status === 'open' && (market.canBack || !this.wallet)) {
      return (
        <Button
          className="Button Button--primary HypeBackButton"
          icon="fas fa-fire"
          onclick={() => openBack(market, this.wallet, (mk, w) => this.backed(mk, w))}
        >
          {t('card.back')}
        </Button>
      );
    }
    return null;
  }

  backed(market: Market, wallet: Wallet) {
    this.market = market;
    this.wallet = wallet;
    m.redraw();
  }

  callOff() {
    if (!this.market || !confirm(tx('bar.call_off_confirm'))) return;
    call('POST', `/markets/${this.market.id}/void`).then(() => {
      this.market!.status = 'void';
      app.alerts.show({ type: 'success' }, t('bar.called_off'));
      m.redraw();
    });
  }
}

export function mountBar(host: Element, discussionId: number): void {
  m.mount(host, { view: () => <Bar discussionId={discussionId} host={host} /> });
}
