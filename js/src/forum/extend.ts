import Extend from 'flarum/common/extenders';
import Model from 'flarum/common/Model';

/** The `hype-markets` type, so a notification's subject can be stored. Only its title is read. */
export class HypeMarketModel extends Model {
  title = Model.attribute<string>('title');
  discussionId = Model.attribute<number | null>('discussionId');
}

export default [new Extend.Store().add('hype-markets', HypeMarketModel)];
