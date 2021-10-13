import app from 'flarum/app';
import { extend } from 'flarum/common/extend';
import Search from 'flarum/forum/components/Search';
import Source from './Source';

app.initializers.add('blomstra-search', () => {
  extend(Search.prototype, 'sourceItems', function (items) {
    items.remove('users');
    items.remove('discussions');

    items.add('discussions', new Source());

    console.log(items);
  });
});
