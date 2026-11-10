import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import Avatar from 'flarum/common/components/Avatar';
import humanTime from 'flarum/common/helpers/humanTime';
import type Mithril from 'mithril';
import Sparkline from './Sparkline';
import { discussionUrl, fakeUser, num, price, signed, times, trend, until, type Market, type Wallet } from './data';
import { pct, t } from '../util';

interface Attrs {
  market: Market;
  wallet: Wallet | null;
  rank?: number;
  onback: (market: Market, wallet: Wallet) => void;
}

declare const m: Mithril.Static;

/** Opens the back form, or the log-in form for a guest. */
export function openBack(market: Market, wallet: Wallet | null, onback: Attrs['onback']): void {
  if (!wallet) {
    app.modal.show(() => import('flarum/forum/components/LogInModal'));
    return;
  }
  app.modal.show(() => import('./index').then((ui) => ({ default: ui.BackModal })), { market, wallet, onback });
}

/**
 * One market on the hottest list or in a portfolio: the discussion, its price
 * and change, the sparkline (with the member's entry marked), who's in, the
 * clock, and either "Back it" or the member's own position.
 */
export default class MarketCard extends Component<Attrs> {
  view() {
    const { market, rank } = this.attrs;
    const url = discussionUrl(market);
    const mine = market.mine;
    const settled = market.status === 'settled' || market.status === 'void';
    const change = mine && !settled ? market.price / mine.entryPrice - 1 : market.change;
    const direction = trend(mine ? (settled ? mine.profit : change) : market.change);

    return (
      <article className={`HypeCard HypeTrend--${direction}` + (mine ? ' HypeCard--mine' : '') + (settled ? ' HypeCard--settled' : '')}>
        <div className="HypeCard-main">
          {rank ? <span className={'HypeCard-rank' + (rank <= 3 ? ` is-top-${rank}` : '')}>{rank}</span> : null}
          <div className="HypeCard-heading">
            {url ? (
              <Link className="HypeCard-title" href={url}>
                {market.discussion!.title}
              </Link>
            ) : (
              <span className="HypeCard-title HypeCard-title--gone">{t('card.gone')}</span>
            )}
            <div className="HypeCard-meta">
              {market.author ? (
                <span className="HypeCard-author">
                  <Avatar user={fakeUser(market.author)} className="HypeAvatar" /> {market.author.displayName}
                </span>
              ) : null}
              <span>{humanTime(market.openedAt)}</span>
              {market.discussion ? (
                <span>
                  <Icon name="far fa-comment" /> {num(Math.max(0, market.discussion.commentCount - 1))}
                </span>
              ) : null}
            </div>
          </div>
          <div className="HypeCard-quote">
            <span className="HypeCard-price">{price(settled ? (mine?.exitPrice ?? market.price) : market.price)}</span>
            <span className="HypeCard-change">
              <Icon name={direction === 'down' ? 'fas fa-caret-down' : direction === 'up' ? 'fas fa-caret-up' : 'fas fa-minus'} /> {pct(change)}
            </span>
          </div>
        </div>

        <Sparkline
          points={market.history}
          entry={mine ? { at: Date.parse(mine.createdAt) / 1000, price: mine.entryPrice } : null}
          className="HypeCard-spark"
          label={t('card.spark_label', { from: price(market.history[0]?.[1] ?? 10), to: price(market.price) }) as any}
        />

        <div className="HypeCard-foot">
          <span className="HypeCard-stat">
            <Icon name="fas fa-fire" /> {t('card.backers', { count: market.backers })}
          </span>
          {market.staked ? <span className="HypeCard-stat">{t('card.staked', { n: num(market.staked) })}</span> : null}
          <span className="HypeCard-stat HypeCard-clock">
            <Icon name="far fa-clock" />{' '}
            {settled
              ? t(market.status === 'void' ? 'card.void' : 'card.settled')
              : market.status === 'open'
                ? t('card.closes', { time: until(market.closesAt) })
                : t('card.settles', { time: until(market.settlesAt) })}
          </span>
          <span className="HypeCard-action">{mine ? this.mine(market, settled) : this.backButton(market)}</span>
        </div>
      </article>
    );
  }

  backButton(market: Market) {
    if (!market.canBack && this.attrs.wallet) return null;
    if (market.status !== 'open') return null;
    return (
      <Button
        className="Button Button--primary HypeBackButton"
        icon="fas fa-fire"
        onclick={() => openBack(market, this.attrs.wallet, this.attrs.onback)}
      >
        {t('card.back')}
      </Button>
    );
  }

  mine(market: Market, settled: boolean) {
    const mine = market.mine!;
    return (
      <span className={'HypeMine HypeTrend--' + trend(mine.profit)}>
        <span className="HypeMine-label">{t('card.you_backed', { n: num(mine.stake), price: price(mine.entryPrice) })}</span>
        <strong className="HypeMine-value">
          {settled
            ? t('card.paid', { n: num(mine.value), profit: signed(mine.profit), x: times(mine.multiplier) })
            : t('card.worth', { n: num(mine.value), profit: signed(mine.profit) })}
        </strong>
        {!settled && market.participants < market.participantsNeeded ? (
          <span className="HypeMine-note">{t('card.needs_people', { n: market.participantsNeeded - market.participants })}</span>
        ) : null}
      </span>
    );
  }
}
