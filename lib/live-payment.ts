/**
 * Live payment status for the payment return page (docs/SSE_CONTRACT.md).
 *
 * The stream is read with fetch() rather than EventSource, so the guest access key and the
 * gateway reference travel as headers, exactly as for verify-payment, and never in a web
 * address. Every message is a hint: the page asks verify-payment for the full answer, and only
 * ever moves forward (not_started → pending → successful / failed). No imports, so the web
 * tests can load this file directly.
 */

export type LiveStatus = 'not_started' | 'pending' | 'successful' | 'failed';

export interface LiveState {
  order_number: string;
  status: LiveStatus;
  order_status: string;
}

export interface SseMessage {
  event: string;
  data: string;
  id: string | null;
}

/** How a stream finished; anything but "final" and "time_limit" means: check the usual way. */
export type StreamOutcome = 'final' | 'time_limit' | 'unavailable' | 'dropped' | 'aborted';

/** The first message must arrive within this time, or the stream is treated as buffered. */
export const FIRST_MESSAGE_MS = 5000;

/**
 * Incremental parser for the text/event-stream format: feed it chunks as they arrive (they may
 * split anywhere, even inside a line) and it returns the complete messages so far. Comments
 * (": keep-alive") and "retry:" lines produce no message.
 */
export class SseParser {
  private buffer = '';
  private event = '';
  private data: string[] = [];
  private id: string | null = null;

  push(chunk: string): SseMessage[] {
    this.buffer += chunk;
    const out: SseMessage[] = [];
    let newline: number;
    while ((newline = this.buffer.search(/\r\n|\r|\n/)) >= 0) {
      const line = this.buffer.slice(0, newline);
      const width = this.buffer.startsWith('\r\n', newline) ? 2 : 1;
      // A lone "\r" at the very end may be the first half of "\r\n": wait for the next chunk.
      if (this.buffer[newline] === '\r' && width === 1 && newline === this.buffer.length - 1) break;
      this.buffer = this.buffer.slice(newline + width);
      if (line === '') {
        if (this.data.length > 0) {
          out.push({ event: this.event || 'message', data: this.data.join('\n'), id: this.id });
        }
        this.event = '';
        this.data = [];
        continue;
      }
      if (line.startsWith(':')) continue;
      const colon = line.indexOf(':');
      const field = colon < 0 ? line : line.slice(0, colon);
      let value = colon < 0 ? '' : line.slice(colon + 1);
      if (value.startsWith(' ')) value = value.slice(1);
      if (field === 'event') this.event = value;
      else if (field === 'data') this.data.push(value);
      else if (field === 'id') this.id = value;
    }
    return out;
  }
}

const RANK: Record<LiveStatus, number> = { not_started: 0, pending: 1, failed: 2, successful: 3 };

/**
 * Whether `next` should replace `prev`. Payment status only moves forward, except that a failed
 * attempt may be followed by a new pending one (the shopper tries again); a closed order
 * (order_status changes, e.g. to cancelled) always counts. Paid is final.
 */
export function isNewerState(prev: LiveState | null, next: LiveState): boolean {
  if (!prev) return true;
  if (prev.status === 'successful') return false;
  if (next.order_status !== prev.order_status) return true;
  if (prev.status === 'failed' && next.status === 'pending') return true;
  return RANK[next.status] > RANK[prev.status];
}

function parseState(data: string): LiveState | null {
  try {
    const value = JSON.parse(data) as Partial<LiveState>;
    if (typeof value.order_number !== 'string' || typeof value.status !== 'string' || !(value.status in RANK)) return null;
    return { order_number: value.order_number, status: value.status as LiveStatus, order_status: String(value.order_status ?? '') };
  } catch {
    return null;
  }
}

export interface OpenStreamOptions {
  baseUrl: string;
  orderNumber: string;
  token?: string | null;
  reference?: string | null;
  signal: AbortSignal;
  onState: (state: LiveState) => void;
  fetchImpl?: typeof fetch;
  firstMessageMs?: number;
}

/**
 * Open the order's live status stream and report each state until it ends. Resolves with how
 * it ended; never throws.
 */
export async function openPaymentStream(options: OpenStreamOptions): Promise<StreamOutcome> {
  const { baseUrl, orderNumber, token, reference, signal, onState } = options;
  const doFetch = options.fetchImpl ?? fetch;
  const headers: Record<string, string> = { Accept: 'text/event-stream' };
  if (token) headers['X-Guest-Access-Token'] = token;
  if (reference) headers['X-Payment-Reference'] = reference;

  // Our own controller, so a stream that sends nothing (a buffering proxy) can be given up.
  const controller = new AbortController();
  const stop = () => controller.abort();
  signal.addEventListener('abort', stop, { once: true });
  let gotMessage = false;
  const watchdog = setTimeout(() => {
    if (!gotMessage) controller.abort();
  }, options.firstMessageMs ?? FIRST_MESSAGE_MS);

  try {
    const res = await doFetch(`${baseUrl}/checkout/orders/${encodeURIComponent(orderNumber)}/events`, {
      headers,
      credentials: 'same-origin',
      cache: 'no-store',
      signal: controller.signal,
    });
    if (!res.ok || !res.body || !String(res.headers.get('content-type') || '').includes('text/event-stream')) {
      return 'unavailable';
    }
    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    const parser = new SseParser();
    for (;;) {
      const { value, done } = await reader.read();
      if (done) return 'dropped';
      for (const message of parser.push(decoder.decode(value, { stream: true }))) {
        if (message.event === 'payment') {
          const state = parseState(message.data);
          if (state) {
            gotMessage = true;
            onState(state);
          }
        } else if (message.event === 'end') {
          try {
            return (JSON.parse(message.data) as { reason?: string }).reason === 'final' ? 'final' : 'time_limit';
          } catch {
            return 'time_limit';
          }
        }
      }
    }
  } catch {
    if (signal.aborted) return 'aborted';
    return gotMessage ? 'dropped' : 'unavailable';
  } finally {
    clearTimeout(watchdog);
    signal.removeEventListener('abort', stop);
    controller.abort();
  }
}

/** Reconnects allowed per page visit before falling back to the usual checks. */
export const MAX_RECONNECTS = 3;
export const RECONNECT_MS = 3000;

/**
 * While the stream is open the page still asks verify-payment, the call that asks Kora or
 * Monnify directly, at about 3, 15, 45 and 90 seconds, so a payment whose provider notice never
 * arrives is still confirmed.
 */
export const LIVE_VERIFY_DELAYS = [3000, 12000, 30000, 45000];
