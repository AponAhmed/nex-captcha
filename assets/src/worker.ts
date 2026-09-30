/**
 * Web Worker: brute-forces the proof-of-work nonce using crypto.subtle,
 * reporting progress and supporting abort. Bundled separately and
 * embedded into widget.ts as a Blob URL (see assets/build.mjs) so the
 * widget ships as a single request with no extra script tag.
 */

interface SolveRequest {
  type: 'solve';
  algorithm: string;
  challenge: string;
  salt: string;
  maxnumber: number;
}

interface AbortRequest {
  type: 'abort';
}

type WorkerRequest = SolveRequest | AbortRequest;

const PROGRESS_BATCH = 500;

let aborted = false;

function toHex(buffer: ArrayBuffer): string {
  const bytes = new Uint8Array(buffer);
  let hex = '';
  for (let i = 0; i < bytes.length; i++) {
    hex += bytes[i].toString(16).padStart(2, '0');
  }
  return hex;
}

async function sha256Hex(input: string): Promise<string> {
  const data = new TextEncoder().encode(input);
  const digest = await crypto.subtle.digest('SHA-256', data);
  return toHex(digest);
}

async function solve(request: SolveRequest): Promise<void> {
  const started = performance.now();
  aborted = false;

  for (let number = 0; number <= request.maxnumber; number++) {
    if (number % PROGRESS_BATCH === 0) {
      if (aborted) {
        return;
      }
      self.postMessage({ type: 'progress', number, maxnumber: request.maxnumber });
      // Yield so an incoming 'abort' message can be processed between batches.
      await new Promise((resolve) => setTimeout(resolve, 0));
      if (aborted) {
        return;
      }
    }

    const hash = await sha256Hex(request.salt + number);

    if (hash === request.challenge) {
      self.postMessage({
        type: 'solved',
        number,
        took: Math.round(performance.now() - started),
      });
      return;
    }
  }

  self.postMessage({ type: 'exhausted' });
}

self.onmessage = (event: MessageEvent<WorkerRequest>) => {
  const message = event.data;

  if (message.type === 'abort') {
    aborted = true;
    return;
  }

  if (message.type === 'solve') {
    void solve(message);
  }
};
