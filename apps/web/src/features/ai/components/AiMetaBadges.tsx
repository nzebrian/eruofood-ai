import type { AiMeta } from '../types';

/** Small provenance line shown under every AI result. */
export function AiMetaBadges({ meta }: { meta: AiMeta }): React.JSX.Element {
  return (
    <p className="ai-meta">
      <span>Provider: {meta.provider}</span> · <span>Model: {meta.model}</span> ·{' '}
      <span>{meta.cached ? 'cached' : 'fresh'}</span> · <span>{meta.tokens.total} tokens</span>
    </p>
  );
}
