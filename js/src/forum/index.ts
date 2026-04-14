import app from 'flarum/forum/app';

import Search, { SearchAttrs, SearchSource } from 'flarum/forum/components/Search';

import { extend } from 'flarum/common/extend';
import ItemList from 'flarum/common/utils/ItemList';

import DiscussionsSearchSource from './SearchSources/DiscussionsSearchSource';
import extendDiscussionState from './PaginatedListStates/extendDiscussionState';

app.initializers.add('blomstra-search', () => {
  const minLength = (app.forum.attribute('blomstraSearchMinLength') as number) || Search.MIN_SEARCH_LEN;
  if (minLength !== Search.MIN_SEARCH_LEN) {
    // Flarum provides no extension point for MIN_SEARCH_LEN, so we overwrite the
    // static property directly. TypeScript `readonly` is compile-time only — at
    // runtime this is a plain property assignment and is safe as long as no code
    // reads MIN_SEARCH_LEN before this initializer runs.
    (Search as any).MIN_SEARCH_LEN = minLength;
  }

  extend(Search.prototype, 'sourceItems', function (this: Search<SearchAttrs>, items: ItemList<SearchSource>) {
    items.replace('discussions', new DiscussionsSearchSource());
  });
});

app.initializers.add(
  'blomstra-search-early',
  () => {
    extendDiscussionState();
  },
  999999
);
