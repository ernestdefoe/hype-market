import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import Sparkline from './Sparkline';
import { call, lossPercent, num, price, until, type Market, type Wallet } from './data';
import { t, tx } from '../util';

/**
 * Back a discussion: pick a stake out of today's hype, see what it could pay,
 * confirm. The server decides everything that matters (price, caps, closing);
 * this only shows the limits it sent.
 */

declare const m: Mithril.Static;

interface Attrs extends IFormModalAttrs {
  market: Market;
  wallet: Wallet;
  onback: (market: Market, wallet: Wallet) => void;
}

export default class BackModal extends FormModal<Attrs> {
  stake = 0;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    this.stake = Math.min(this.max(), Math.max(this.attrs.wallet.minStake, Math.round(this.max() / 2 / 5) * 5));
  }

  max(): number {
    return Math.min(this.attrs.wallet.maxStake, this.attrs.wallet.left);
  }

  className() {
    return 'HypeBackModal Modal--small';
  }

  title() {
    return t('back.title');
  }

  content() {
    const { market, wallet } = this.attrs;
    const max = this.max();
    const backsLeft = wallet.maxBacks - wallet.backs;
    const loss = lossPercent();
    // With a loss share, show what a flat call costs and where it breaks even; without, the upside.
    const outcomes = loss ? [1, 2, 3] : [1.5, 2, 3];
    const presets = [wallet.minStake, 10, 25, max]
      .filter((n, i, all) => n >= wallet.minStake && n <= max && all.indexOf(n) === i)
      .sort((a, b) => a - b);

    return (
      <div className="Modal-body">
        <div className="HypeBackModal-market">
          <div className="HypeBackModal-title">{market.discussion?.title}</div>
          <div className="HypeBackModal-price">
            <span className="HypeBackModal-priceLabel">{t('back.price_now')}</span>
            <strong>{price(market.price)}</strong>
          </div>
          <Sparkline points={market.history} className="HypeTrend--up" />
        </div>

        {max < wallet.minStake || backsLeft <= 0 ? (
          <p className="HypeBackModal-empty">
            <Icon name="fas fa-hourglass-half" /> {t(backsLeft <= 0 ? 'back.no_backs' : 'back.no_hype', { time: until(wallet.resetsAt) })}
          </p>
        ) : (
          <div className="Form">
            <div className="Form-group">
              <label for="HypeBackModal-stake">{t('back.stake')}</label>
              <div className="HypeBackModal-presets" role="group" aria-label={tx('back.presets')}>
                {presets.map((n) => (
                  <button
                    type="button"
                    className={'HypeBackModal-preset' + (this.stake === n ? ' is-active' : '')}
                    aria-pressed={this.stake === n ? 'true' : 'false'}
                    onclick={() => (this.stake = n)}
                  >
                    {n === max ? t('back.max', { n }) : n}
                  </button>
                ))}
              </div>
              <div className="HypeBackModal-slider">
                <input
                  id="HypeBackModal-stake"
                  type="range"
                  min={wallet.minStake}
                  max={max}
                  step={1}
                  value={this.stake}
                  oninput={(e: InputEvent) => (this.stake = Number((e.target as HTMLInputElement).value))}
                />
                <output className="HypeBackModal-amount" for="HypeBackModal-stake">
                  <Icon name="fas fa-fire" /> {num(this.stake)}
                </output>
              </div>
              <p className="helpText">
                {t('back.left', { left: wallet.left, allowance: wallet.allowance, backs: backsLeft, time: until(wallet.resetsAt) })}
              </p>
            </div>

            <ul className="HypeBackModal-outcomes">
              {outcomes.map((x) => (
                <li className={x - loss / 100 < 1 ? 'is-loss' : ''}>
                  <span>{x === 1 ? t('back.if_flat') : t('back.if_grows', { x })}</span>
                  <strong>{t('back.you_get', { n: num(Math.floor(this.stake * Math.max(0, x - loss / 100))) })}</strong>
                </li>
              ))}
            </ul>

            <ul className="HypeBackModal-rules">
              <li>
                <Icon name="fas fa-clock" /> {t('back.rule_settles', { time: until(market.settlesAt) })}
              </li>
              <li>
                {loss
                  ? [<Icon name="fas fa-triangle-exclamation" />, ' ', t('back.rule_loss', { percent: loss, x: 1 + loss / 100 })]
                  : [<Icon name="fas fa-shield-halved" />, ' ', t('back.rule_safe')]}
              </li>
              <li>
                <Icon name="fas fa-users" /> {t('back.rule_people', { n: market.participantsNeeded })}
              </li>
              <li>
                <Icon name="fas fa-comment-slash" /> {t('back.rule_own')}
              </li>
            </ul>

            <div className="Form-group">
              <Button type="submit" className="Button Button--primary HypeBackButton HypeBackModal-submit" icon="fas fa-fire" loading={this.loading}>
                {t('back.submit', { n: num(this.stake) })}
              </Button>
            </div>
          </div>
        )}
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    call<{ market: Market; wallet: Wallet }>('POST', `/discussions/${this.attrs.market.discussion!.id}/back`, { stake: this.stake })
      .then((res) => {
        this.attrs.onback(res.market, res.wallet);
        app.alerts.show({ type: 'success' }, t('back.done', { n: num(this.stake) }));
        this.hide();
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
