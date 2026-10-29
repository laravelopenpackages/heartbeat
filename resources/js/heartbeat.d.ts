export interface HeartbeatOptions {
  /** The beat endpoint (route name `heartbeat`). */
  url: string;
  /** Seconds between beats until the server says otherwise. Default 15. */
  interval?: number;
  /** Extra request headers. */
  headers?: Record<string, string>;
  /** Default 'same-origin'. */
  credentials?: RequestCredentials;
  onError?: (error: unknown) => void;
  /** A fetch to use instead of the global one. */
  fetch?: typeof fetch;
}

export interface HeartbeatClient {
  /** Call `listener` whenever `topic` changed. Returns a function that stops listening. */
  on(topic: string, listener: (topic: string) => void): () => void;
  /** Start beating (the first beat only learns the server's `now`). */
  start(): void;
  /** Beat now instead of waiting — e.g. right after the user saved something. */
  beatNow(): void;
  stop(): void;
}

/** What the endpoint answers. */
export interface BeatAnswer {
  /** Server time (ms): send it back as `since` on the next beat. */
  now: number;
  /** The asked-for topics that changed since `since`. */
  changed: string[];
  /** Seconds to wait before the next beat. */
  interval: number;
}

export function createHeartbeat(options: HeartbeatOptions): HeartbeatClient;
