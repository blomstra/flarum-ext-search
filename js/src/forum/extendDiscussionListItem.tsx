import { extend } from 'flarum/common/extend';
import DiscussionListItem from 'flarum/forum/components/DiscussionListItem';
import TerminalPost from 'flarum/forum/components/TerminalPost';

export default function extendDiscussionListItem() {
  extend(DiscussionListItem.prototype, 'infoItems', function (items) {
    const params = this.attrs.params;

    if (!params.q) return;

    const hasFieldSort = params.sort && params.sort !== 'relevance';

    if (hasFieldSort) {
      // Field sort active (latest, oldest, top, …): replace excerpt with TerminalPost.
      // An excerpt is meaningless when results are ordered by date/count rather than relevance.
      items.remove('excerpt');
      if (!items.has('terminalPost')) {
        items.add('terminalPost', <TerminalPost discussion={this.attrs.discussion} lastPost={!this.showFirstPost()} />);
      }
    } else if (!items.has('excerpt')) {
      // Relevance mode but no excerpt (mostRelevantPost was null or non-comment type).
      // Fall back to TerminalPost so the info section is never silently empty.
      items.add('terminalPost', <TerminalPost discussion={this.attrs.discussion} lastPost={true} />);
    }
  });
}
