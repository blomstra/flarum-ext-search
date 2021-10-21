import app from 'flarum/forum/app';

import Search from 'flarum/forum/components/Search';

import { extend } from 'flarum/common/extend';
import ItemList from 'flarum/common/utils/ItemList';

import DiscussionsSearchSource from './SearchSources/DiscussionsSearchSource';
import extendDiscussionState from './PaginatedListStates/extendDiscussionState';

app.initializers.add('blomstra-search', () => {
  extend(Search.prototype, 'sourceItems', function (this: Search, items: ItemList) {
    items.remove('users');
    items.remove('discussions');

    /* if (app.forum.attribute('canViewForum')) */ items.add('discussions', new DiscussionsSearchSource());

    // console.log(items);
  });

  extendDiscussionState();
});
