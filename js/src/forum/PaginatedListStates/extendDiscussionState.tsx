import app from 'flarum/forum/app';

import { override } from 'flarum/common/extend';

import DiscussionListState from 'flarum/forum/states/DiscussionListState';

// A query counts as a real fulltext search only if, after removing Flarum gambit
// operators (tag:foo, is:private, byobu:bar, author:baz, …), something remains.
// This mirrors SearchController::getSearch() on the backend so the two agree on
// what warrants an Elasticsearch query. Gambit-only lists (e.g. the byobu inbox,
// which sends "byobu:<slug> is:private") stay on the native Flarum endpoint, whose
// database sorting is authoritative — routing them through ES adds load and risks
// diverging from core ordering.
function hasFulltextTerm(q: unknown): boolean {
  return (
    typeof q === 'string' &&
    q
      .split(' ')
      .filter((token) => token && !/^\w+:/.test(token))
      .join(' ')
      .trim().length > 0
  );
}

export default function extendDiscussionState() {
  override(DiscussionListState.prototype, 'loadPage', async function (this: DiscussionListState, original, page: number = 1) {
    const preloaded = app.data.apiDocument || null;

    // If existing payload is given or no fulltext search is made, fall back on native page.
    if (preloaded || !hasFulltextTerm(this.requestParams()?.filter?.q)) return original.call(this, page);

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
