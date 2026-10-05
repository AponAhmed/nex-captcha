/**
 * Renders the slider puzzle challenge and reports the drag trace to the
 * server for verification.
 *
 * The gap position is never sent to the client (see
 * Challenge\SliderChallenge's own docblock) -- a real 2D jigsaw puzzle,
 * not a 1D horizontal slider, so both X and Y are secret. Unlike the
 * PoW path this widget can never locally confirm success -- status text
 * stays neutral guidance, not a "Verified." claim the server might
 * contradict.
 *
 * All positions here (the trace, nxc_slider_x/y, the keyboard step)
 * live in the backend's own native 300x150 coordinate space regardless
 * of how large the widget actually renders on screen -- e.g.
 * wp-login.php's own form is narrower than 300px, so the background
 * image and the piece both get visually scaled down by the browser.
 * The piece's own on-screen left/top/size are expressed as percentages
 * of that native space (toPercent()), so they scale in lockstep with
 * the image (confirmed live: a fixed-pixel piece position drifted away
 * from the image's own visually-shrunk gap, failing bad_position every
 * time). Pointer drag converts real on-screen pixel movement back into
 * native units via the frame's actual rendered size for the same
 * reason.
 */

import { setStatus } from './status';

// Mirrors Challenge\SliderChallenge's own constants (src/Challenge/SliderChallenge.php).
const WIDTH = 300;
const HEIGHT = 150;
const PIECE_SIZE = 40;
const KEYBOARD_STEP = 5; // Matches SliderChallenge::TOLERANCE, so a keyboard user can land exactly.
const MAX_LEFT = WIDTH - PIECE_SIZE;
const MAX_TOP = HEIGHT - PIECE_SIZE;

function toPercent(value: number, of: number): string {
  return `${(value / of) * 100}%`;
}

export interface SliderPayload {
  mode: 'slider';
  image: string;
  piece: string;
  salt: string;
  signature: string;
}

interface TracePoint {
  x: number;
  y: number;
  t: number;
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value));
}

/**
 * Removes any slider markup/hidden inputs from a previous render --
 * widget.ts re-invokes renderSlider() on every pre-expiry refresh, and
 * without this, hidden inputs with the same name would pile up.
 */
function clearPreviousRender(widget: HTMLElement): void {
  widget.querySelector('.nxc-widget__slider')?.remove();
  widget.querySelectorAll('input[name^="nxc_slider_"]').forEach((input) => input.remove());
}

function hiddenInput(name: string, value: string): HTMLInputElement {
  const input = document.createElement('input');
  input.type = 'hidden';
  input.name = name;
  input.value = value;
  return input;
}

export function renderSlider(widget: HTMLElement, payload: SliderPayload, onRequestAlternative: () => void): void {
  clearPreviousRender(widget);

  setStatus(widget, 'Drag the puzzle piece into the gap, then submit the form.', 'info');

  const container = document.createElement('div');
  container.className = 'nxc-widget__slider';

  // The piece's top/left/width/height are percentages resolved against
  // THIS frame specifically -- it must be exactly the image's own box
  // (via the same aspect-ratio), not the outer container, which is
  // taller once the alternative button below is added. Percentages
  // against the taller container previously threw off the piece's
  // vertical size/position relative to the image.
  const frame = document.createElement('div');
  frame.className = 'nxc-widget__slider-frame';

  const background = document.createElement('img');
  background.className = 'nxc-widget__slider-bg';
  background.src = payload.image;
  background.alt = '';
  background.draggable = false;

  const piece = document.createElement('div');
  piece.className = 'nxc-widget__slider-piece';
  piece.tabIndex = 0;
  piece.setAttribute('role', 'slider');
  piece.setAttribute('aria-label', 'Drag with arrow keys or a pointer to align the puzzle piece with the gap');
  piece.style.backgroundImage = `url(${payload.piece})`;
  piece.style.width = toPercent(PIECE_SIZE, WIDTH);
  piece.style.height = toPercent(PIECE_SIZE, HEIGHT);

  frame.append(background, piece);
  container.append(frame);

  // Outside the frame (below it), so the frame's own height always
  // matches the image's height exactly, regardless of the button.
  const alternative = document.createElement('button');
  alternative.type = 'button';
  alternative.className = 'nxc-widget__slider-alt';
  alternative.textContent = 'Accessible alternative';
  alternative.addEventListener('click', onRequestAlternative);
  container.append(alternative);

  const saltInput = hiddenInput('nxc_slider_salt', payload.salt);
  const signatureInput = hiddenInput('nxc_slider_signature', payload.signature);
  const xInput = hiddenInput('nxc_slider_x', '0');
  const yInput = hiddenInput('nxc_slider_y', '0');
  const traceInput = hiddenInput('nxc_slider_trace', '[]');

  widget.append(container, saltInput, signatureInput, xInput, yInput, traceInput);

  const traceStart = performance.now();
  const trace: TracePoint[] = [];
  let currentX = 0;
  let currentY = 0;

  // Starts at the top-left corner (0, 0) -- the piece has to be
  // dragged/keyed to wherever the gap actually is, on both axes.
  const setPosition = (left: number, top: number): void => {
    currentX = clamp(left, 0, MAX_LEFT);
    currentY = clamp(top, 0, MAX_TOP);
    piece.style.left = toPercent(currentX, WIDTH);
    piece.style.top = toPercent(currentY, HEIGHT);
    // No native min/max/now trio here -- a single numeric range can't
    // describe a 2D position, so aria-valuetext carries it as text.
    piece.setAttribute('aria-valuetext', `Position ${currentX}, ${currentY}`);
    xInput.value = String(currentX);
    yInput.value = String(currentY);
    trace.push({ x: currentX, y: currentY, t: performance.now() - traceStart });
    traceInput.value = JSON.stringify(trace);
  };

  setPosition(0, 0);

  let dragStartLeft = 0;
  let dragStartTop = 0;
  let dragStartClientX = 0;
  let dragStartClientY = 0;

  const onPointerMove = (event: PointerEvent): void => {
    // Real on-screen pixels -> native units, via however large the
    // frame actually rendered -- 1:1 only when the widget renders at
    // its full native size. Computed per axis for robustness, though
    // the frame's locked aspect-ratio keeps both scales equal in
    // practice.
    const frameBox = frame.getBoundingClientRect();
    const scaleX = WIDTH / (frameBox.width || WIDTH);
    const scaleY = HEIGHT / (frameBox.height || HEIGHT);
    setPosition(
      dragStartLeft + (event.clientX - dragStartClientX) * scaleX,
      dragStartTop + (event.clientY - dragStartClientY) * scaleY,
    );
  };

  const onPointerUp = (event: PointerEvent): void => {
    piece.removeEventListener('pointermove', onPointerMove);
    piece.removeEventListener('pointerup', onPointerUp);
    piece.releasePointerCapture(event.pointerId);
  };

  piece.addEventListener('pointerdown', (event: PointerEvent) => {
    dragStartLeft = currentX;
    dragStartTop = currentY;
    dragStartClientX = event.clientX;
    dragStartClientY = event.clientY;
    piece.setPointerCapture(event.pointerId);
    piece.addEventListener('pointermove', onPointerMove);
    piece.addEventListener('pointerup', onPointerUp);
  });

  piece.addEventListener('keydown', (event: KeyboardEvent) => {
    if (event.key === 'ArrowLeft') {
      event.preventDefault();
      setPosition(currentX - KEYBOARD_STEP, currentY);
    } else if (event.key === 'ArrowRight') {
      event.preventDefault();
      setPosition(currentX + KEYBOARD_STEP, currentY);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setPosition(currentX, currentY - KEYBOARD_STEP);
    } else if (event.key === 'ArrowDown') {
      event.preventDefault();
      setPosition(currentX, currentY + KEYBOARD_STEP);
    } else if (event.key === 'Home') {
      event.preventDefault();
      setPosition(0, 0);
    } else if (event.key === 'End') {
      event.preventDefault();
      setPosition(MAX_LEFT, MAX_TOP);
    }
  });
}
