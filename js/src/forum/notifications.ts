import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import { t } from './util';

/** "Your hype call paid off": opens the member's portfolio. */
export class PaidNotification extends Notification {
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

/** "Your hype call didn't pay off" (flat, or called off). */
export class FlatNotification extends PaidNotification {
  icon() {
    return 'fas fa-scale-balanced';
  }
  content() {
    return t('notification.flat');
  }
}
