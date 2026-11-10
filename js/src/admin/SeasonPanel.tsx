import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

declare const m: Mithril.Static;

const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-hype-market.admin.season.${key}`, params);

interface Season {
  id: number;
  name: string;
  startedAt: string;
  endedAt: string | null;
}

/**
 * The season box: what's running, who leads, whether the scheduler is
 * running (nothing settles without it), and "End this season now".
 */
export default class SeasonPanel extends Component {
  seasons: Season[] | null = null;
  current: number | null = null;
  leaders: Array<{ user: { displayName: string }; profit: number }> = [];
  name = '';
  busy = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.load();
  }

  load() {
    app.request<any>({ method: 'GET', url: app.forum.attribute('apiUrl') + '/hype/leaderboard?limit=3' }).then((board) => {
      this.seasons = board.seasons;
      this.current = board.currentSeasonId;
      this.leaders = board.rows;
      this.name = '';
      m.redraw();
    });
  }

  view() {
    if (!this.seasons) return <LoadingIndicator />;
    const current = this.seasons.find((s) => s.id === this.current);
    // Seasons turn over at midnight UTC: show that date, not the day before in American time zones.
    const date = (iso: string) => new Date(iso).toLocaleDateString(undefined, { timeZone: 'UTC' });
    const tick = Number(app.data.settings['ernestdefoe-hype-market.last_tick'] || 0);
    const minutes = tick ? Math.round((Date.now() / 1000 - tick) / 60) : null;

    return (
      <div className="Form-group HypeSeasonPanel">
        <label>{t('heading')}</label>
        <p className={'HypeSeasonPanel-tick' + (minutes === null || minutes > 15 ? ' is-late' : '')}>
          {minutes === null ? t('tick_never') : minutes > 15 ? t('tick_late', { minutes }) : t('tick_ok', { minutes })}
        </p>
        {current && <p>{t('current', { name: current.name, date: date(current.startedAt) })}</p>}
        <p className="helpText">
          {this.leaders.length
            ? t('leaders', { names: this.leaders.map((r) => `${r.user.displayName} (+${r.profit})`).join(', ') })
            : t('no_leaders')}
        </p>
        <div className="HypeSeasonPanel-row">
          <input
            className="FormControl"
            maxlength={80}
            aria-label={extractText(t('next_name'))}
            placeholder={extractText(t('next_name'))}
            value={this.name}
            oninput={(e: InputEvent) => (this.name = (e.target as HTMLInputElement).value)}
          />
          <Button className="Button Button--danger" loading={this.busy} disabled={!this.name.trim()} onclick={() => this.end()}>
            {t('end')}
          </Button>
        </div>
        <p className="helpText">{t('end_help')}</p>
        {this.seasons.some((s) => s.endedAt) && (
          <ul className="HypeSeasonPanel-past">
            {this.seasons
              .filter((s) => s.endedAt)
              .map((s) => (
                <li>{t('past', { name: s.name, from: date(s.startedAt), to: date(s.endedAt!) })}</li>
              ))}
          </ul>
        )}
      </div>
    );
  }

  end() {
    if (!confirm(extractText(t('confirm')))) return;
    this.busy = true;
    app
      .request<any>({ method: 'POST', url: app.forum.attribute('apiUrl') + '/hype/seasons', body: { name: this.name } })
      .then(() => {
        app.alerts.show({ type: 'success' }, t('ended'));
        this.load();
      })
      .catch(() => {})
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }
}
