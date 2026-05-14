import { extend } from 'flarum/common/extend';
import DiscussionListItem from 'flarum/forum/components/DiscussionListItem';
import TerminalPost from 'flarum/forum/components/TerminalPost';
import highlight from 'flarum/common/helpers/highlight';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

/**
 * Builds a regex from a search phrase that matches both ASCII and diacritic-decorated
 * forms when applied to NFD-normalised text.
 *
 * Steps:
 *  1. Fold the phrase to ASCII (NFD decompose + drop combining U+0300–U+036F).
 *  2. Suffix each character with [̀-ͯ]* so e.g. 'e' also matches
 *     'é' (NFD form of é), 'ë' (NFD form of ë), etc.
 *
 * Must be used alongside .normalize('NFD') on the content being searched, because
 * precomposed NFC characters (é = U+00E9) are not matched — only the decomposed
 * NFD form (e + U+0301) is.
 */
function buildFoldRegex(phrase: string): RegExp {
  const folded = phrase.normalize('NFD').replace(/[̀-ͯ]/g, '');

  function toPattern(str: string): string {
    return str
      .replace(/[.*+?^${}()|[\]\\]/g, '\\$&') // escape regex special chars
      .replace(/./gsu, '$&[̀-ͯ]*'); // allow combining diacritics after each char
  }

  const fullPattern = toPattern(folded);
  const wordPattern = folded.trim().split(/\s+/).map(toPattern).join('|');

  return new RegExp(`${fullPattern}|${wordPattern}`, 'gui');
}

export default function extendDiscussionListItem() {
  // Replace core's plain regex with a fold-aware one so e.g. "cafe" matches "café".
  extend(DiscussionListItem.prototype, 'getJumpTo', function () {
    if (this.attrs.params.q) {
      this.highlightRegExp = buildFoldRegex(this.attrs.params.q);
    }
  });

  // NFD-normalise the title before highlighting so the fold-aware regex matches
  // precomposed diacritics (NFC é → NFD e + U+0301). Visually identical in browsers.
  extend(DiscussionListItem.prototype, 'mainItems', function (items: ItemList<Mithril.Children>) {
    if (!this.attrs.params.q || !items.has('title')) return;

    const discussion = this.attrs.discussion;
    items.setContent('title', <h2 className="DiscussionListItem-title">{highlight(discussion.title().normalize('NFD'), this.highlightRegExp)}</h2>);
  });

  // Re-render the excerpt against NFD-normalised content so combining diacritics
  // are separate characters that the fold-aware regex can match.
  extend(DiscussionListItem.prototype, 'infoItems', function (items: ItemList<Mithril.Children>) {
    if (!this.attrs.params.q) return;

    if (items.has('excerpt')) {
      const post = this.attrs.discussion.mostRelevantPost() || this.attrs.discussion.firstPost();
      if (post && post.contentType() === 'comment') {
        items.setContent('excerpt', highlight((post.contentPlain() ?? '').normalize('NFD'), this.highlightRegExp, 175));
      }
    } else if (!items.has('terminalPost')) {
      items.add('terminalPost', <TerminalPost discussion={this.attrs.discussion} lastPost={true} />);
    }
  });
}
