import app from 'flarum/forum/app';

import { override } from 'flarum/common/extend';

import DiscussionListState from 'flarum/forum/states/DiscussionListState';

export default function extendDiscussionState() {
  override(DiscussionListState.prototype, 'loadPage', async function (this: DiscussionListState, original, page: number = 1) {
    const preloaded = app.data.apiDocument || null;

    // Any q — including gambit-only lists like the byobu inbox ("is:private") — goes
    // through Elasticsearch. ES filters (is_private + recipient) then sorts, which stays
    // fast on large forums; the native SQL path degenerates into a full last_posted_at
    // index walk for users with few private discussions (see git history for the 10s
    // regression this avoids). Correct ordering is handled server-side in SearchController.
    if (preloaded || !this.requestParams()?.filter?.q) return original.call(this, page);

    const params = this.requestParams();
    params.page = {
      offset: this.pageSize * (page - 1),
      ...params.page,
    };

    if (Array.isArray(params.include)) {
      params.include = params.include.join(',');
    }

    // Always request mostRelevantPost when searching so core can render a
    // highlighted excerpt regardless of the active sort order.
    if (params.filter?.q) {
      const includes = params.include ? params.include.split(',') : [];
      if (!includes.includes('mostRelevantPost')) {
        includes.push('mostRelevantPost');
        params.include = includes.join(',');
      }
    }

    // Construct API search URI
    const url = `${app.forum.attribute('apiUrl')}/blomstra/search/${this.type}`;

    // Make API GET request
    const results = await app.request({ params, url, method: 'GET' });

    // Parse API response into models and push to store
    return app.store.pushPayload(results);
  });
}
