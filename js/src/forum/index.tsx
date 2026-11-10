import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Icon from 'flarum/common/components/Icon';
import Notification from 'flarum/forum/components/Notification';
import CommentPost from 'flarum/forum/components/CommentPost';
import type Mithril from 'mithril';
import { pct, t } from './util';

export { default as extend } from './extend';

/*
 * forum.js is loaded on every page, so it only wires things up: the route,
 * the sidebar link, the alerts, the "backed by 12" chip on discussion cards
 * (from the `hype` attribute the list already carries) and the slot for the
 * market bar under a young discussion's first post. The page, the bar and
 * the back form are one chunk fetched when first needed.
 */

class PaidNotification extends Notification {
  icon() {
    return 'fas fa-arrow-trend-up';
  }
  href() {
    return app.route('hype') + '?tab=portfolio';
  }
  content() {
    return t('notification.paid');
  }
  excerpt() {
    return (this.attrs.notification as any).subject()?.attribute?.('title') ?? '';
  }
}

class FlatNotification extends PaidNotification {
  icon() {
    return 'fas fa-scale-balanced';
  }
  content() {
    return t('notification.flat');
  }
}

app.initializers.add('ernestdefoe/hype-market', () => {
  app.routes['hype'] = {
    path: '/hype',
    component: () => import('./components').then(async (ui) => (await ui.loadCore(), { default: ui.HypePage })),
  };

  app.notificationComponents.hypePaid = PaidNotification;
  app.notificationComponents.hypeFlat = FlatNotification;

  // String form: these components live in lazy chunks, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<any>) {
    if (!app.forum.attribute('hypeCanView') || !app.forum.attribute('hypeNav')) return;
    items.add('hype', LinkButton.component({ href: app.route('hype'), icon: 'fas fa-fire' }, t('nav')), 74);
  });

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    if (!app.forum.attribute('hypeCanView')) return;
    items.add('hypePaid', { name: 'hypePaid', icon: 'fas fa-arrow-trend-up', label: t('notification.preference_paid') });
    items.add('hypeFlat', { name: 'hypeFlat', icon: 'fas fa-scale-balanced', label: t('notification.preference_flat') });
  });

  // "🔥 Backed by 12 · +84%" on discussion cards.
  extend('flarum/forum/components/DiscussionListItem', 'infoItems', function (this: any, items: ItemList<Mithril.Children>) {
    const hype = this.attrs.discussion.attribute('hype');
    if (!hype || hype.backers < 1) return;
    // Only the real risers glow: three times the opening price or more.
    const hot = hype.change >= 2;
    items.add(
      'hype',
      <span className={'HypeChip' + (hot ? ' HypeChip--hot' : '')} title={String(t('chip.title', { price: hype.price }))}>
        <Icon name="fas fa-fire" />
        <span className="HypeChip-text">{t('chip.backed', { count: hype.backers })}</span>
        {hype.change > 0.005 ? <span className="HypeChip-change">{pct(hype.change)}</span> : null}
      </span>,
      5
    );
  });

  // The market bar under a young discussion's first post.
  extend(CommentPost.prototype, 'content', function (this: CommentPost, items: Mithril.Children[]) {
    const post: any = this.attrs.post;
    if (post.number() === 1 && !post.isHidden() && post.discussion()?.attribute('hype') && app.forum.attribute('hypeCanView')) {
      items.push(<div className="HypeBarHost" />);
    }
  });

  extend(CommentPost.prototype, ['oncreate', 'onupdate'], function (this: CommentPost) {
    const host = this.element.querySelector<HTMLElement>('.HypeBarHost');
    const discussion: any = (this.attrs.post as any).discussion();
    if (!host || host.dataset.mounted) return;
    host.dataset.mounted = '1';
    import('./components').then(({ mountBar }) => mountBar(host, Number(discussion.id())));
  });
});
