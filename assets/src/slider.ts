/**
 * Renders the slider puzzle challenge and reports the drag trace to the
 * server for verification.
 *
 * The gap X position is never sent to the client (see
 * Challenge\SliderChallenge's own docblock), so unlike the PoW path this
 * widget can never locally confirm success -- status text stays neutral
 * guidance, not a "Verified." claim the server might contradict.
 *
 * All positions here (the trace, nxc_slider_x, the keyboard step) live
 * in the backend's own native 0-300 coordinate space regardless of how
 * large the widget actually renders on screen -- e.g. wp-login.php's
 * own form is narrower than 300px, so the background image and the
 * piece both get visually scaled down by the browser. The piece's own
 * on-screen left/top/size are expressed as percentages of that native
 * space (toPercent()), so they scale in lockstep with the image
 * (confirmed live: a fixed-pixel piece position drifted away from the
 * image's own visually-shrunk gap, failing bad_position every time).
 * Pointer drag converts real on-screen pixel movement back into native
 * units via the container's actual rendered width for the same reason.
 */

// Mirrors Challenge\SliderChallenge's own constants (src/Challenge/SliderChallenge.php).
const WIDTH = 300;
const HEIGHT = 150;
const PIECE_SIZE = 40;
const KEYBOARD_STEP = 5; // Matches SliderChallenge::TOLERANCE, so a keyboard user can land exactly.
const MAX_LEFT = WIDTH - PIECE_SIZE;

function toPercent(value: number, of: number): string {
  return `${(value / of) * 100}%`;
}

export interface SliderPayload {
  mode: 'slider';
  image: string;
  piece: string;
  pieceY: number;
  salt: string;
  signature: string;
}

interface TracePoint {
  x: number;
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

  const status = widget.querySelector<HTMLElement>('.nxc-widget__status');
  if (status) {
    status.textContent = 'Drag the puzzle piece into the gap, then submit the form.';
  }

  const container = document.createElement('div');
  container.className = 'nxc-widget__slider';

  // The piece's top/left/width/height are percentages resolved against
  // THIS frame specifically -- it must be exactly the image's own box
  // (via the same aspect-ratio), not the outer container, which is
  // taller once the alternative button below is added. Percentages
  // against the taller container previously threw off the piece's
  // vertical size/position relative to the image (not the horizontal
  // drag math, which only ever used the container's WIDTH -- same
  // basis either way).
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
  piece.setAttribute('aria-label', 'Drag to align the puzzle piece with the gap');
  piece.setAttribute('aria-valuemin', '0');
  piece.setAttribute('aria-valuemax', String(MAX_LEFT));
  piece.style.backgroundImage = `url(${payload.piece})`;
  piece.style.top = toPercent(payload.pieceY, HEIGHT);
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
  const traceInput = hiddenInput('nxc_slider_trace', '[]');

  widget.append(container, saltInput, signatureInput, xInput, traceInput);

  const traceStart = performance.now();
  const trace: TracePoint[] = [];
  let currentX = 0;

  const setPosition = (left: number): void => {
    currentX = clamp(left, 0, MAX_LEFT);
    piece.style.left = toPercent(currentX, WIDTH);
    piece.setAttribute('aria-valuenow', String(currentX));
    xInput.value = String(currentX);
    trace.push({ x: currentX, t: performance.now() - traceStart });
    traceInput.value = JSON.stringify(trace);
  };

  setPosition(0);

  let dragStartLeft = 0;
  let dragStartClientX = 0;

  const onPointerMove = (event: PointerEvent): void => {
    // Real on-screen pixels -> native units, via however large the
    // frame actually rendered (WIDTH/renderedWidth) -- 1:1 only when
    // the widget renders at its full native size.
    const renderedWidth = frame.getBoundingClientRect().width || WIDTH;
    const scale = WIDTH / renderedWidth;
    setPosition(dragStartLeft + (event.clientX - dragStartClientX) * scale);
  };

  const onPointerUp = (event: PointerEvent): void => {
    piece.removeEventListener('pointermove', onPointerMove);
    piece.removeEventListener('pointerup', onPointerUp);
    piece.releasePointerCapture(event.pointerId);
  };

  piece.addEventListener('pointerdown', (event: PointerEvent) => {
    dragStartLeft = currentX;
    dragStartClientX = event.clientX;
    piece.setPointerCapture(event.pointerId);
    piece.addEventListener('pointermove', onPointerMove);
    piece.addEventListener('pointerup', onPointerUp);
  });

  piece.addEventListener('keydown', (event: KeyboardEvent) => {
    if (event.key === 'ArrowLeft') {
      event.preventDefault();
      setPosition(currentX - KEYBOARD_STEP);
    } else if (event.key === 'ArrowRight') {
      event.preventDefault();
      setPosition(currentX + KEYBOARD_STEP);
    } else if (event.key === 'Home') {
      event.preventDefault();
      setPosition(0);
    } else if (event.key === 'End') {
      event.preventDefault();
      setPosition(MAX_LEFT);
    }
  });
}
