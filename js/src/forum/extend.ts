import Extend from 'flarum/common/extenders';
import Model from 'flarum/common/Model';
import { FlatNotification, PaidNotification } from './notifications';

/** The `hype-markets` type, so a notification's subject can be stored. Only its title is read. */
export class HypeMarketModel extends Model {
  title = Model.attribute<string>('title');
  discussionId = Model.attribute<number | null>('discussionId');
}

export default [
  new Extend.Store().add('hype-markets', HypeMarketModel),

  // The page is a lazy chunk: core loads it the first time someone opens /hype.
  new Extend.Routes().add('hype', '/hype', () => import('./components').then(async (ui) => (await ui.loadCore(), { default: ui.HypePage }))),

  new Extend.Notification().add('hypePaid', PaidNotification).add('hypeFlat', FlatNotification),
];
