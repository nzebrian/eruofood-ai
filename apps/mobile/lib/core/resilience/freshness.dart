/// How much a piece of data can be trusted to describe the present.
///
/// Mirrors the server's `FreshnessState` exactly, and deliberately so: two
/// vocabularies for the same idea is how a client ends up rendering as "live"
/// something the server labelled degraded.
///
/// The client has one signal the server does not — whether *it* can reach the
/// network — and the server has one the client cannot know: how old the data
/// was when it left. [FreshnessState] carries the server's half; [Connectivity]
/// carries the client's. Neither guesses at the other.
enum FreshnessState {
  /// Observed recently enough to act on.
  online('online'),

  /// Older than we would like. Usable, but must be shown with its age.
  degraded('degraded'),

  /// The source is known to be unreachable. A positive fact.
  offline('offline'),

  /// We do not know how old this is. The honest answer when there is no answer,
  /// and the default for anything undated.
  staleUnknown('stale_unknown');

  const FreshnessState(this.wire);

  /// The value the API uses.
  final String wire;

  static FreshnessState fromWire(String? value) {
    for (final state in FreshnessState.values) {
      if (state.wire == value) return state;
    }
    // Unrecognised, absent, or from a newer server than this build understands.
    // Never optimistic: an unknown freshness is a stale one.
    return FreshnessState.staleUnknown;
  }

  /// Whether this may be shown without a caveat. Only [online].
  bool get mayPresentAsLive => this == FreshnessState.online;

  /// Whether it is worth showing at all, labelled with its age.
  bool get isUsableWithCaveat =>
      this == FreshnessState.online || this == FreshnessState.degraded;
}

/// What the *client* can currently reach.
///
/// Separate from [FreshnessState] because they answer different questions and
/// can disagree in both directions: a phone with full signal can hold data the
/// server said was stale, and a phone with no signal can hold data that was
/// perfectly fresh a second before the connection dropped.
enum Connectivity {
  online,

  /// Reachable but unreliable — timeouts, retries succeeding slowly.
  degraded,

  offline,

  /// Not yet established. The state at cold start, before the first call.
  unknown;

  bool get canReachServer =>
      this == Connectivity.online || this == Connectivity.degraded;
}
