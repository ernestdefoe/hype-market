import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import SeasonPanel from './SeasonPanel';

const t = (key: string) => app.translator.trans(`ernestdefoe-hype-market.admin.${key}`, {}, true);
const setting = (key: string) => `ernestdefoe-hype-market.${key}`;

const number = (key: string, min: number, max: number, priority: number) =>
  [() => ({ setting: setting(key), type: 'number', min, max, label: t(`settings.${key}`), help: t(`settings.${key}_help`) }), priority] as const;

export default [
  new Extend.Admin()
    .setting(() => ({ setting: setting('nav'), type: 'boolean', label: t('settings.nav') }), 100)
    .setting(...number('daily_allowance', 10, 100000, 95))
    .setting(...number('min_stake', 1, 100000, 94))
    .setting(...number('max_stake', 1, 100000, 93))
    .setting(...number('max_backs', 1, 100, 92))
    .setting(...number('backing_hours', 1, 336, 91))
    .setting(...number('window_hours', 6, 336, 90))
    .setting(...number('min_account_days', 0, 365, 85))
    .setting(...number('min_posts', 0, 1000, 84))
    .setting(...number('min_participants', 1, 50, 83))
    .setting(...number('loss_percent', 0, 100, 82))
    .setting(
      () => ({
        setting: setting('season_mode'),
        type: 'select',
        options: { monthly: String(t('settings.season_monthly')), manual: String(t('settings.season_manual')) },
        default: 'monthly',
        label: t('settings.season_mode'),
        help: t('settings.season_mode_help'),
      }),
      80
    )
    .customSetting(() => <SeasonPanel />, 50)
    .permission(() => ({ permission: 'hype.view', icon: 'fas fa-fire', label: t('permissions.view'), allowGuest: true }), 'view', 55)
    .permission(() => ({ permission: 'hype.back', icon: 'fas fa-fire', label: t('permissions.back') }), 'reply', 55)
    .permission(() => ({ permission: 'hype.moderate', icon: 'fas fa-ban', label: t('permissions.moderate') }), 'moderate', 55),
];
