import app from 'flarum/forum/app';

import Search, { SearchAttrs, SearchSource } from 'flarum/forum/components/Search';

import { extend } from 'flarum/common/extend';
import ItemList from 'flarum/common/utils/ItemList';

import DiscussionsSearchSource from './SearchSources/DiscussionsSearchSource';
import extendDiscussionState from './PaginatedListStates/extendDiscussionState';
import extendDiscussionListItem from './extendDiscussionListItem';

app.initializers.add('blomstra-search', () => {
  extend(Search.prototype, 'sourceItems', function (this: Search<SearchAttrs>, items: ItemList<SearchSource>) {
    // app.forum is not available during initializers (it is set after they run),
    // so read the setting lazily here, at first render time.
    const minLength = (app.forum.attribute('blomstraSearchMinLength') as number) || Search.MIN_SEARCH_LEN;
    if (minLength !== Search.MIN_SEARCH_LEN) {
      (Search as any).MIN_SEARCH_LEN = minLength;
    }

    items.replace('discussions', new DiscussionsSearchSource());
  });
});

app.initializers.add(
  'blomstra-search-early',
  () => {
    extendDiscussionState();
    extendDiscussionListItem();
  },
  999999
);
