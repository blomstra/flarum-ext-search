import app from 'flarum/forum/app';
import highlight from 'flarum/common/helpers/highlight';
import LinkButton from 'flarum/common/components/LinkButton';
import Link from 'flarum/common/components/Link';
import { SearchSource } from 'flarum/forum/components/Search';
import type Mithril from 'mithril';

/**
 * The `DiscussionsSearchSource` finds and displays discussion search results in
 * the search dropdown.
 */
export default class DiscussionsSearchSource implements SearchSource {
  /**
   * Map of lowercase search queries to their respective model results
   */
  protected results = new Map<string, any[]>();

  /**
   * Model name used for assembling API endpoint URL, and for frontend DOM.
   */
  private type = 'discussions';

  async search(query: string): Promise<void> {
    query = query.toLowerCase();

    this.results.set(query, []);

    const params = {
      filter: { q: query },
      page: { limit: 3 },
      include: 'mostRelevantPost',
    };

    // Construct API search URI
    const url = `${app.forum.attribute('apiUrl')}/blomstra/search/${this.type}`;

    // Make API GET request
    const results = await app.request({ params, url, method: 'GET' });

    // Parse API response into models and push to store
    const models = app.store.pushPayload(results);

    // Add models to results map
    this.results.set(query, models);
  }

  view(query: string): Array<Mithril.Vnode> {
    query = query.toLowerCase();

    // Get results from map
    const queryResults = this.results.get(query) || [];

    const results = queryResults.map((discussion: any) => {
      const mostRelevantPost = discussion.mostRelevantPost();

      return (
        <li className="DiscussionSearchResult" data-index={`${this.type}${discussion.id()}`}>
          <Link href={app.route.discussion(discussion, mostRelevantPost && mostRelevantPost.number())}>
            <div className="DiscussionSearchResult-title">{highlight(discussion.title(), query)}</div>
            {!!mostRelevantPost && <div className="DiscussionSearchResult-excerpt">{highlight(mostRelevantPost.contentPlain(), query, 100)}</div>}
          </Link>
        </li>
      );
    });

    return [
      <li className="Dropdown-header">{app.translator.trans('core.forum.search.discussions_heading')}</li>,
      <li>
        <LinkButton icon="fas fa-search" href={app.route('index', { q: query })}>
          {app.translator.trans('core.forum.search.all_discussions_button', { query })}
        </LinkButton>
      </li>,
      ...results,
    ];
  }
}
