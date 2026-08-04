import { useCallback, useEffect, useRef, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { searchApi } from '../searchApi';
import {
  formatPrice,
  SEARCH_TYPES,
  SORT_OPTIONS,
  type SearchDocument,
  type SearchFilters,
  type SearchResults,
} from '../types';

/** Global search & discovery: autocomplete, advanced filters, ranked results and recommendations. */
export function SearchPage(): React.JSX.Element {
  const [term, setTerm] = useState('');
  const [type, setType] = useState('global');
  const [sort, setSort] = useState('relevance');
  const [filters, setFilters] = useState<SearchFilters>({});
  const [showFilters, setShowFilters] = useState(false);

  const [results, setResults] = useState<SearchResults | null>(null);
  const [suggestions, setSuggestions] = useState<string[]>([]);
  const [trending, setTrending] = useState<string[]>([]);
  const [recommended, setRecommended] = useState<SearchDocument[]>([]);
  const [loading, setLoading] = useState(false);
  const debounce = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    searchApi
      .trending()
      .then((r) => setTrending(r.trending))
      .catch(() => setTrending([]));
    searchApi
      .recommendations('trending', 'food', undefined, 6)
      .then((r) => setRecommended(r.items))
      .catch(() => setRecommended([]));
  }, []);

  const runSearch = useCallback(
    (searchTerm: string): void => {
      setLoading(true);
      searchApi
        .search(searchTerm, type, sort, filters, 1)
        .then(setResults)
        .catch(() => setResults(null))
        .finally(() => setLoading(false));
    },
    [type, sort, filters],
  );

  const onTermChange = (value: string): void => {
    setTerm(value);
    if (debounce.current) clearTimeout(debounce.current);
    if (value.trim() === '') {
      setSuggestions([]);
      return;
    }
    debounce.current = setTimeout(() => {
      searchApi
        .autocomplete(value, type)
        .then((r) => setSuggestions(r.suggestions))
        .catch(() => setSuggestions([]));
    }, 150);
  };

  const submit = (e: React.FormEvent): void => {
    e.preventDefault();
    setSuggestions([]);
    runSearch(term);
  };

  const openHit = (documentId: string, position: number, url: string | null): void => {
    if (results?.query_id) {
      void searchApi.recordClick(results.query_id, documentId, position);
    }
    if (url) window.location.assign(url);
  };

  const setFilter = (key: keyof SearchFilters, value: string): void => {
    setFilters((f) => ({ ...f, [key]: value === '' ? undefined : value }));
  };

  return (
    <Layout>
      <h1>Search</h1>

      <form className="search-bar" onSubmit={submit} role="search">
        <div className="search-bar__input">
          <input
            value={term}
            onChange={(e) => onTermChange(e.target.value)}
            placeholder="Search foods, recipes, restaurants, products…"
            aria-label="Search"
            autoComplete="off"
          />
          {suggestions.length > 0 && (
            <ul className="search-suggestions">
              {suggestions.map((s) => (
                <li key={s}>
                  <button
                    type="button"
                    onClick={() => {
                      setTerm(s);
                      setSuggestions([]);
                      runSearch(s);
                    }}
                  >
                    {s}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
        <select value={type} onChange={(e) => setType(e.target.value)} aria-label="Type">
          {SEARCH_TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
        <Button type="submit" busy={loading}>
          Search
        </Button>
        <button type="button" className="button button--secondary" onClick={() => setShowFilters((v) => !v)}>
          Filters
        </button>
      </form>

      {showFilters && (
        <div className="search-filters">
          <label>
            Sort
            <select value={sort} onChange={(e) => setSort(e.target.value)}>
              {SORT_OPTIONS.map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </select>
          </label>
          <label>
            Region
            <input value={filters.region ?? ''} onChange={(e) => setFilter('region', e.target.value)} />
          </label>
          <label>
            Cuisine
            <input value={filters.cuisine ?? ''} onChange={(e) => setFilter('cuisine', e.target.value)} />
          </label>
          <label>
            Difficulty
            <select value={filters.difficulty ?? ''} onChange={(e) => setFilter('difficulty', e.target.value)}>
              <option value="">Any</option>
              <option value="easy">Easy</option>
              <option value="medium">Medium</option>
              <option value="hard">Hard</option>
            </select>
          </label>
          <label>
            Min rating
            <input
              type="number"
              min={0}
              max={5}
              value={filters.min_rating ?? ''}
              onChange={(e) => setFilter('min_rating', e.target.value)}
            />
          </label>
          <label>
            Max cooking time (min)
            <input
              type="number"
              min={0}
              value={filters.max_cooking_time ?? ''}
              onChange={(e) => setFilter('max_cooking_time', e.target.value)}
            />
          </label>
        </div>
      )}

      {results === null ? (
        <>
          {trending.length > 0 && (
            <section className="search-trending">
              <h2>Trending searches</h2>
              <div className="chip-row">
                {trending.map((t) => (
                  <button
                    key={t}
                    className="chip"
                    onClick={() => {
                      setTerm(t);
                      runSearch(t);
                    }}
                  >
                    {t}
                  </button>
                ))}
              </div>
            </section>
          )}
          <RecommendationStrip title="Popular right now" items={recommended} />
        </>
      ) : (
        <div className="search-results-layout">
          {Object.keys(results.facets).length > 0 && (
            <aside className="search-facets">
              {Object.entries(results.facets).map(([name, values]) => (
                <div key={name} className="search-facet">
                  <h3>{name}</h3>
                  <ul>
                    {Object.entries(values).map(([value, count]) => (
                      <li key={value}>
                        {value} <span className="muted">({count})</span>
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </aside>
          )}

          <section className="search-results">
            <p className="muted">{results.total} result(s)</p>
            {results.hits.length === 0 ? (
              <p className="muted">No matches. Try broadening your filters.</p>
            ) : (
              <ul className="search-hit-list">
                {results.hits.map((hit, index) => (
                  <li key={hit.document.id}>
                    <button className="search-hit" onClick={() => openHit(hit.document.id, index, hit.document.url)}>
                      <div className="search-hit__body">
                        <span className="search-hit__title">{hit.document.title}</span>
                        <span className="search-hit__meta">
                          <span className={`badge badge--${hit.document.type}`}>{hit.document.type}</span>
                          {hit.document.region ? <span> · {hit.document.region}</span> : null}
                          {hit.document.rating > 0 ? <span> · ★ {hit.document.rating.toFixed(1)}</span> : null}
                          {hit.document.price_minor !== null ? <span> · {formatPrice(hit.document.price_minor)}</span> : null}
                        </span>
                        {hit.highlight ? <span className="search-hit__snippet">{hit.highlight}</span> : null}
                      </div>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>
      )}
    </Layout>
  );
}

function RecommendationStrip({
  title,
  items,
}: {
  title: string;
  items: SearchDocument[];
}): React.JSX.Element | null {
  if (items.length === 0) return null;
  return (
    <section className="search-recs">
      <h2>{title}</h2>
      <div className="search-recs__row">
        {items.map((item) => (
          <a key={item.id} className="search-rec-card" href={item.url ?? '#'}>
            <span className="search-rec-card__title">{item.title}</span>
            <span className="search-rec-card__meta">
              <span className={`badge badge--${item.type}`}>{item.type}</span>
              {item.rating > 0 ? <span> ★ {item.rating.toFixed(1)}</span> : null}
            </span>
          </a>
        ))}
      </div>
    </section>
  );
}
