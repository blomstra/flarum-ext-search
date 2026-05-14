import { extend } from 'flarum/common/extend';
import DiscussionListItem from 'flarum/forum/components/DiscussionListItem';
import TerminalPost from 'flarum/forum/components/TerminalPost';

export default function extendDiscussionListItem() {
  extend(DiscussionListItem.prototype, 'infoItems', function (items) {
    const params = this.attrs.params;

    if (!params.q) return;

    // Core's infoItems() already adds a highlighted 'excerpt' when params.q is set
    // and a comment-type mostRelevantPost is available. We only add a TerminalPost
    // fallback when neither is present so the info section is never silently empty.
    if (!items.has('excerpt') && !items.has('terminalPost')) {
      items.add('terminalPost', <TerminalPost discussion={this.attrs.discussion} lastPost={true} />);
    }
  });
}
