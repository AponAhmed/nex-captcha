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
import { setStatus } from './status';

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

/**
 * Disabled while the invisible challenge is being fetched/solved -- the
 * user has nothing to do yet, so a clickable submit button would let
 * them submit with nxc_token still empty. Re-enabled the moment there's
 * either a completed token (invisible mode, verified) or a visible task
 * the user is meant to complete instead (slider/math) -- see
 * solveWidget()'s own call sites for exactly where each applies.
 */
export function setButtonsDisabled(widget: HTMLElement, disabled: boolean): void {
  const form = widget.closest('form');
  if (!form) {
    return;
  }

  form.querySelectorAll<HTMLButtonElement | HTMLInputElement>('button[type="submit"], input[type="submit"]').forEach((button) => {
    button.disabled = disabled;
  });
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

/**
 * crypto.subtle (needed by worker.ts's SHA-256 loop) only exists in a
 * secure context -- HTTPS, or literally "localhost". A site served over
 * plain HTTP would otherwise leave every visitor's invisible-PoW
 * challenge permanently unsolvable with no visible error: the worker
 * throws, nxc_token never gets filled, and every submission fails as a
 * malformed token. Falling back to the slider (which needs no Web
 * Crypto at all) keeps login possible either way.
 */
export function hasWebCrypto(): boolean {
  return typeof crypto !== 'undefined' && typeof crypto.subtle !== 'undefined';
}

async function solveWidget(widget: HTMLElement, forcedMode?: string, isRefresh = false): Promise<void> {
  const form = widget.dataset.form;
  const restUrl = widget.dataset.restUrl;
  const input = tokenInput(widget);

  if (!form || !restUrl || !input) {
    return;
  }

  // A background pre-expiry refresh (scheduleRefresh) re-solves behind
  // the scenes -- the previous token is still sitting in the field and
  // still valid, so touching the button here would just be a visible
  // glitch for someone mid-submit, not a real guard against anything.
  // manageButton() is the single gate every call site below goes
  // through instead of calling setButtonsDisabled() directly.
  const manageButton = (disabled: boolean) => {
    if (!isRefresh) {
      setButtonsDisabled(widget, disabled);
    }
  };

  manageButton(true);

  const effectiveMode = forcedMode ?? (hasWebCrypto() ? undefined : 'slider');

  let payload: ChallengePayload;

  try {
    const modeParam = effectiveMode ? `&mode=${encodeURIComponent(effectiveMode)}` : '';
    const response = await fetch(`${restUrl}challenge?form=${encodeURIComponent(form)}${modeParam}`, {
      credentials: 'omit',
    });
    if (!response.ok) {
      throw new Error(`Unexpected status ${response.status}`);
    }
    payload = await response.json();
  } catch (error) {
    console.warn('NexCaptcha: could not fetch a challenge.', error);
    setStatus(widget, 'Verification unavailable.', 'error');
    manageButton(false);
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
    // From here it's a visible task the user completes themselves --
    // correctness can't be confirmed client-side (see renderSlider()'s
    // own docblock), so the button re-enables rather than staying
    // gated on something this widget can never locally verify.
    manageButton(false);
    scheduleRefresh(widget, payload.salt);
    return;
  }

  if (payload.mode === 'math') {
    renderMath(widget, payload);
    manageButton(false);
    scheduleRefresh(widget, payload.salt);
    return;
  }

  // Defense in depth: the server was already asked for slider above
  // when Web Crypto is missing, but if an 'invisible' payload comes
  // back anyway, attempting the worker would just fail silently with
  // nxc_token forever empty -- surface it instead.
  if (!hasWebCrypto()) {
    console.warn('NexCaptcha: Web Crypto is unavailable in this context; cannot solve the invisible challenge.');
    setStatus(widget, 'Verification unavailable.', 'error');
    manageButton(false);
    return;
  }

  const solved = await runWorker(payload);

  if (!solved) {
    setStatus(widget, 'Verification failed, please try again.', 'error');
    manageButton(false);
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
  setStatus(widget, 'Verified.', 'verified');
  manageButton(false);

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
      void solveWidget(widget, undefined, true);
    }, remaining);
  }
}

function initWidget(widget: HTMLElement): void {
  if (started.has(widget)) {
    return;
  }

  trackInteraction(widget);

  // The static template already shows "Verifying you are human…" from
  // first paint; disabling here (rather than waiting for solveWidget()'s
  // own lazy IntersectionObserver/focusin trigger to fire) closes the
  // gap where the button would otherwise be clickable while that text
  // is already telling the visitor verification is in progress.
  setButtonsDisabled(widget, true);

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

/**
 * Contact Form 7 forms commonly get submitted more than once on the
 * same page load (no reload/redirect, unlike login/register/comments),
 * so a solved invisible-mode token would otherwise sit stale/reused
 * for a second send. CF7's own Turnstile/reCAPTCHA modules handle this
 * identically (document.addEventListener('wpcf7submit', ...) resetting
 * their widget); harmless on any page without CF7 since the event
 * simply never fires there.
 */
function reinitOnCf7Submit(event: Event): void {
  const form = event.target as HTMLElement;
  form.querySelectorAll<HTMLElement>('.nxc-widget').forEach((widget) => {
    started.delete(widget);
    void solveWidget(widget);
  });
}

function init(): void {
  document.querySelectorAll<HTMLElement>('.nxc-widget').forEach(initWidget);
  document.addEventListener('wpcf7submit', reinitOnCf7Submit);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
