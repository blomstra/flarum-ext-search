import app from 'flarum/forum/app';

import Search, { SearchAttrs, SearchSource } from 'flarum/forum/components/Search';

import { extend } from 'flarum/common/extend';
import ItemList from 'flarum/common/utils/ItemList';

import DiscussionsSearchSource from './SearchSources/DiscussionsSearchSource';
import extendDiscussionState from './PaginatedListStates/extendDiscussionState';

app.initializers.add('blomstra-search', () => {
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
