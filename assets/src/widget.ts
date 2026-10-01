/**
 * Finds .nxc-widget elements, fetches a challenge on first focus/view,
 * and hands it off to the right renderer for its mode -- a Web Worker
 * for invisible PoW, slider.ts for the slider puzzle, math.ts for the
 * math/text question (reached either by risk escalation or by the
 * slider's own "Accessible alternative" link) -- before the form
 * submits. Refreshes before the challenge expires, for whichever mode
 * was last served.
 */

import { renderSlider, type SliderPayload } from './slider';
import { renderMath, type MathPayload } from './math';

// Injected at build time (assets/build.mjs) with worker.ts's bundled
// source, so the worker runs from a Blob URL with no extra request.
declare const NXC_WORKER_SOURCE: string;

interface PowPayload {
  mode: 'invisible';
  algorithm: string;
  challenge: string;
  maxnumber: number;
  salt: string;
  signature: string;
}

type ChallengePayload = PowPayload | SliderPayload | MathPayload;

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

export function interactionInput(widget: HTMLElement): HTMLInputElement | null {
  return widget.querySelector<HTMLInputElement>('input[name="nxc_interacted"]');
}

/**
 * Marks nxc_interacted on the form's first focusin, pointerdown or
 * input event -- focusin covers keyboard/Tab navigation too, so this
 * doesn't create an accessibility gap. Armed from initWidget(), not
 * gated behind the focus/view trigger that starts solving: it needs to
 * be listening independently of that.
 */
export function trackInteraction(widget: HTMLElement): void {
  const form = widget.closest('form');
  const input = interactionInput(widget);

  if (!form || !input) {
    return;
  }

  const mark = () => {
    input.value = '1';
    form.removeEventListener('focusin', mark);
    form.removeEventListener('pointerdown', mark);
    form.removeEventListener('input', mark);
  };

  form.addEventListener('focusin', mark, { once: true });
  form.addEventListener('pointerdown', mark, { once: true });
  form.addEventListener('input', mark, { once: true });
}

/**
 * Clears slider and math markup/fields unconditionally, regardless of
 * which mode is about to render -- each renderer's own cleanup only
 * knows about its own prior same-type render (see slider.ts's and
 * math.ts's own clearPreviousRender()), not the other type's leftovers
 * from a mode switch.
 */
function clearOtherChallengeMarkup(widget: HTMLElement): void {
  widget.querySelectorAll('.nxc-widget__slider, .nxc-widget__math-js').forEach((element) => element.remove());
  widget.querySelectorAll('input[name^="nxc_slider_"], input[name^="nxc_math_"]').forEach((input) => input.remove());
}

function parseExpiry(salt: string): number | null {
  const [, query] = salt.split('?');
  if (!query) {
    return null;
  }
  const expires = new URLSearchParams(query).get('expires');
  return expires ? parseInt(expires, 10) : null;
}

function runWorker(payload: PowPayload): Promise<SolvedMessage | null> {
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

async function solveWidget(widget: HTMLElement, forcedMode?: string): Promise<void> {
  const form = widget.dataset.form;
  const restUrl = widget.dataset.restUrl;
  const input = tokenInput(widget);

  if (!form || !restUrl || !input) {
    return;
  }

  let payload: ChallengePayload;

  try {
    const modeParam = forcedMode ? `&mode=${encodeURIComponent(forcedMode)}` : '';
    const response = await fetch(`${restUrl}challenge?form=${encodeURIComponent(form)}${modeParam}`, {
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

  // Switching between challenge types (e.g. the slider's "Accessible
  // alternative" link) must clear whichever OTHER mode last rendered --
  // each renderer's own cleanup only knows about its own markup/fields,
  // and Verifier dispatches on field presence, so a stale nxc_slider_*
  // left behind would hijack a math submission down the wrong path.
  clearOtherChallengeMarkup(widget);

  if (payload.mode === 'slider') {
    renderSlider(widget, payload, () => {
      void solveWidget(widget, 'math');
    });
    scheduleRefresh(widget, payload.salt);
    return;
  }

  if (payload.mode === 'math') {
    renderMath(widget, payload);
    scheduleRefresh(widget, payload.salt);
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

  scheduleRefresh(widget, payload.salt);
}

/**
 * Shared by both modes: the challenge's own salt-embedded expiry
 * decides when to re-fetch, regardless of whether that re-fetch ends
 * up re-solving a PoW challenge or re-rendering a slider puzzle.
 */
function scheduleRefresh(widget: HTMLElement, salt: string): void {
  const expires = parseExpiry(salt);
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

  trackInteraction(widget);

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
