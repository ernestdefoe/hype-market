/*
 * The one lazy chunk: the page, the market bar, the cards and the back form.
 * forum.js only wires things up; everything here loads the first time the
 * page or a young discussion needs it. One entry on purpose: two chunks
 * sharing the card would make webpack emit a numbered shared chunk that
 * jsDirectory doesn't publish.
 */
export { default as HypePage, loadCore } from './HypePage';
export { default as BackModal } from './BackModal';
export { mountBar } from './MarketBar';
