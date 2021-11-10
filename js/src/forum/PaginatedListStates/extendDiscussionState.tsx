import app from 'flarum/forum/app';

import { override } from 'flarum/common/extend';

import DiscussionListState from 'flarum/forum/states/DiscussionListState';

export default function extendDiscussionState() {
  override(DiscussionListState.prototype, 'loadPage', async function (this: DiscussionListState, original, page: number = 1) {
    if (!this.requestParams()?.filter?.q) return original.call(this, page);

    const params = this.requestParams();
    params.page = {
      offset: this.pageSize * (page - 1),
      ...params.page,
    };

    if (Array.isArray(params.include)) {
      params.include = params.include.join(',');
    }

    // Construct API search URI
    const url = `${app.forum.attribute('apiUrl')}/blomstra/search/${this.type}`;

    // Make API GET request
    const results = await app.request({ params, url, method: 'GET' });

    // Parse API response into models and push to store
    return app.store.pushPayload(results);
  });
}
