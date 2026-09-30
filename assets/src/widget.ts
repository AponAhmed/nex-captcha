/**
 * Finds .nxc-widget elements, fetches a challenge on first focus/view,
 * solves it in a Web Worker and fills the hidden nxc_token input before
 * the form submits. Refreshes the token before it expires.
 */

// Injected at build time (assets/build.mjs) with worker.ts's bundled
// source, so the worker runs from a Blob URL with no extra request.
declare const NXC_WORKER_SOURCE: string;

interface ChallengePayload {
  mode: string;
  algorithm: string;
  challenge: string;
  maxnumber: number;
  salt: string;
  signature: string;
}

interface SolvedMessage {
  type: 'solved';
  number: number;
  took: number;
}

interface ExhaustedMessage {
  type: 'exhausted';
}

interface ProgressMessage {
  type: 'progress';
  number: number;
  maxnumber: number;
}

type WorkerResponse = SolvedMessage | ExhaustedMessage | ProgressMessage;

const REFRESH_BUFFER_MS = 30_000;
const MIN_REFRESH_MS = 5_000;
const started = new WeakSet<Element>();

function setStatus(widget: HTMLElement, text: string): void {
  const status = widget.querySelector<HTMLElement>('.nxc-widget__status');
  if (status) {
    status.textContent = text;
  }
}

function tokenInput(widget: HTMLElement): HTMLInputElement | null {
  return widget.querySelector<HTMLInputElement>('input[name="nxc_token"]');
}

function parseExpiry(salt: string): number | null {
  const [, query] = salt.split('?');
  if (!query) {
    return null;
  }
  const expires = new URLSearchParams(query).get('expires');
  return expires ? parseInt(expires, 10) : null;
}

function runWorker(payload: ChallengePayload): Promise<SolvedMessage | null> {
  return new Promise((resolve) => {
    const blob = new Blob([NXC_WORKER_SOURCE], { type: 'application/javascript' });
    const url = URL.createObjectURL(blob);
    const worker = new Worker(url);

    worker.onmessage = (event: MessageEvent<WorkerResponse>) => {
      const message = event.data;
      if (message.type === 'solved') {
        worker.terminate();
        URL.revokeObjectURL(url);
        resolve(message);
      } else if (message.type === 'exhausted') {
        worker.terminate();
        URL.revokeObjectURL(url);
        resolve(null);
      }
    };

    worker.postMessage({
      type: 'solve',
      algorithm: payload.algorithm,
      challenge: payload.challenge,
      salt: payload.salt,
      maxnumber: payload.maxnumber,
    });
  });
}

async function solveWidget(widget: HTMLElement): Promise<void> {
  const form = widget.dataset.form;
  const restUrl = widget.dataset.restUrl;
  const input = tokenInput(widget);

  if (!form || !restUrl || !input) {
    return;
  }

  let payload: ChallengePayload;

  try {
    const response = await fetch(`${restUrl}challenge?form=${encodeURIComponent(form)}`, {
      credentials: 'omit',
    });
    if (!response.ok) {
      throw new Error(`Unexpected status ${response.status}`);
    }
    payload = await response.json();
  } catch (error) {
    console.warn('NexCaptcha: could not fetch a challenge.', error);
    setStatus(widget, 'Verification unavailable.');
    return;
  }

  const solved = await runWorker(payload);

  if (!solved) {
    setStatus(widget, 'Verification failed, please try again.');
    return;
  }

  const token = {
    algorithm: payload.algorithm,
    challenge: payload.challenge,
    salt: payload.salt,
    signature: payload.signature,
    number: solved.number,
    took: solved.took,
  };

  input.value = btoa(JSON.stringify(token));
  setStatus(widget, 'Verified.');

  const expires = parseExpiry(payload.salt);
  if (expires) {
    const remaining = Math.max(expires * 1000 - Date.now() - REFRESH_BUFFER_MS, MIN_REFRESH_MS);
    setTimeout(() => {
      void solveWidget(widget);
    }, remaining);
  }
}

function initWidget(widget: HTMLElement): void {
  if (started.has(widget)) {
    return;
  }

  const start = () => {
    if (started.has(widget)) {
      return;
    }
    started.add(widget);
    void solveWidget(widget);
  };

  const observer = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (entry.isIntersecting) {
        observer.disconnect();
        start();
      }
    }
  });
  observer.observe(widget);

  const form = widget.closest('form');
  form?.addEventListener('focusin', start, { once: true });
}

function init(): void {
  document.querySelectorAll<HTMLElement>('.nxc-widget').forEach(initWidget);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
