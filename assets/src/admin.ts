/**
 * Vanilla JS behaviour for the Settings -> NexCaptcha admin screen:
 * AJAX settings save, the Logs drawer's open/close and its AJAX
 * filter/page/refresh, plus toast feedback. General/Forms/Security
 * need no JS beyond the save handler -- toggle switches are pure CSS
 * (:checked), everything else is a normal field.
 *
 * admin-ajax.php always defines a global `ajaxurl` on every wp-admin
 * screen -- no localization needed to get at it.
 */
declare const ajaxurl: string;

interface LogsParams {
  result: string;
  form: string;
  paged: number;
}

let lastLogsParams: LogsParams = { result: '', form: '', paged: 1 };

function drawer(): HTMLElement | null {
  return document.getElementById('nxc-log-drawer');
}

function backdrop(): HTMLElement | null {
  return document.getElementById('nxc-log-drawer-backdrop');
}

function drawerContent(): HTMLElement | null {
  return document.querySelector('.nxc-drawer__content');
}

function openDrawer(): void {
  drawer()?.classList.add('is-open');
  backdrop()?.classList.add('is-open');
}

function closeDrawer(): void {
  drawer()?.classList.remove('is-open');
  backdrop()?.classList.remove('is-open');
}

function setActiveFilter(result: string): void {
  document.querySelectorAll('.nxc-filter').forEach((button) => {
    button.classList.toggle('is-active', (button as HTMLElement).dataset.result === result);
  });
}

/**
 * The one AJAX round-trip every log operation (icon-rail filter, the
 * form/result <select> mini-filter, pagination links, refresh) funnels
 * through -- re-renders LogsTable server-side for the given params and
 * swaps .nxc-drawer__content's innerHTML, so there's exactly one query
 * implementation (SettingsPage::ajax_filter_logs()) behind all of them.
 */
async function fetchLogs(params: Partial<LogsParams>): Promise<void> {
  const content = drawerContent();
  const nonce = drawer()?.dataset.nonce;
  if (!content || !nonce) {
    return;
  }

  lastLogsParams = { ...lastLogsParams, ...params };
  setActiveFilter(lastLogsParams.result);
  content.classList.add('is-loading');

  try {
    const body = new URLSearchParams();
    body.set('action', 'nxc_filter_logs');
    body.set('nonce', nonce);
    body.set('nxc_result', lastLogsParams.result);
    body.set('nxc_form', lastLogsParams.form);
    body.set('paged', String(lastLogsParams.paged));

    const response = await fetch(ajaxurl, { method: 'POST', body });
    const data = await response.json();

    if (data?.success) {
      content.innerHTML = data.data.html;
    }
  } catch {
    // Network/parse failure: leave the previous rows in place rather
    // than blanking a working view over a transient error.
  } finally {
    content.classList.remove('is-loading');
  }
}

function paginationParams(href: string): Partial<LogsParams> {
  const url = new URL(href, window.location.href);

  return {
    paged: Math.max(1, parseInt(url.searchParams.get('paged') ?? '1', 10) || 1),
    result: url.searchParams.get('nxc_result') ?? '',
    form: url.searchParams.get('nxc_form') ?? '',
  };
}

const TOAST_ICONS: Record<'success' | 'error', string> = {
  success:
    '<svg class="nxc-toast__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>',
  error:
    '<svg class="nxc-toast__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18" /><path d="m6 6 12 12" /></svg>',
};

function showToast(type: 'success' | 'error', message: string): void {
  const toaster = document.getElementById('nxc-toaster');
  if (!toaster) {
    return;
  }

  const toast = document.createElement('div');
  toast.className = `nxc-toast nxc-toast--${type}`;
  toast.innerHTML = `${TOAST_ICONS[type]}<span>${message}</span>`;
  toaster.appendChild(toast);

  requestAnimationFrame(() => toast.classList.add('is-visible'));

  window.setTimeout(() => {
    toast.classList.remove('is-visible');
    window.setTimeout(() => toast.remove(), 250);
  }, 3000);
}

/**
 * Progressively enhances the settings <form> (action="options.php"
 * stays in the markup as the no-JS fallback): posts to admin-ajax.php
 * instead, so saving never reloads the screen. All three panels'
 * Save buttons are type="submit" inside this one shared form, so this
 * single listener covers whichever one was actually clicked.
 */
async function saveSettings(event: SubmitEvent): Promise<void> {
  const form = event.currentTarget as HTMLFormElement;
  event.preventDefault();

  const submitter = (event.submitter as HTMLButtonElement | null) ?? form.querySelector('button[type="submit"]');
  const label = submitter?.querySelector<HTMLElement>('.nxc-btn-label');
  const originalLabel = label?.textContent ?? '';
  const nonce = form.dataset.nonce ?? '';

  if (submitter) {
    submitter.disabled = true;
  }
  if (label) {
    label.textContent = 'Saving…';
  }

  try {
    const body = new FormData(form);
    body.set('action', 'nxc_save_settings');
    body.set('nonce', nonce);

    const response = await fetch(ajaxurl, { method: 'POST', body });
    const data = await response.json();

    if (data?.success) {
      showToast('success', data.data?.message ?? 'Settings saved.');
    } else {
      showToast('error', data.data?.message ?? 'Could not save settings.');
    }
  } catch {
    showToast('error', 'Could not save settings.');
  } finally {
    if (submitter) {
      submitter.disabled = false;
    }
    if (label) {
      label.textContent = originalLabel;
    }
  }
}

function init(): void {
  const drawerEl = drawer();
  if (drawerEl?.dataset.defaultOpen === '1') {
    openDrawer();
  }

  document.getElementById('nxc-settings-form')?.addEventListener('submit', (event) => {
    void saveSettings(event as SubmitEvent);
  });

  document.addEventListener('click', (event) => {
    const target = event.target as HTMLElement;

    if (target.closest('#nxc-view-logs')) {
      openDrawer();
      return;
    }

    if (target.closest('.nxc-drawer__close') || target === backdrop()) {
      closeDrawer();
      return;
    }

    if (target.closest('.nxc-drawer__refresh')) {
      void fetchLogs(lastLogsParams);
      return;
    }

    const filterButton = target.closest<HTMLElement>('.nxc-filter');
    if (filterButton) {
      void fetchLogs({ result: filterButton.dataset.result ?? '', paged: 1 });
      return;
    }

    const paginationLink = target.closest<HTMLAnchorElement>('.nxc-drawer__content .tablenav-pages a');
    if (paginationLink) {
      event.preventDefault();
      void fetchLogs(paginationParams(paginationLink.href));
    }
  });

  document.addEventListener('submit', (event) => {
    const form = (event.target as HTMLElement).closest('form[data-ajax-filter]');
    if (!form) {
      return;
    }

    event.preventDefault();
    const data = new FormData(form as HTMLFormElement);
    // The list table's own "jump to page" box (name="paged") lives in
    // this same <form> alongside the result/form filter selects, so a
    // plain Enter-to-submit there lands here too -- read its value
    // instead of always resetting to page 1, matching how this form
    // already behaved as a real GET submit before AJAX.
    const paged = Math.max(1, parseInt(String(data.get('paged') ?? '1'), 10) || 1);
    void fetchLogs({
      result: String(data.get('nxc_result') ?? ''),
      form: String(data.get('nxc_form') ?? ''),
      paged,
    });
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}

export { fetchLogs, paginationParams, saveSettings, showToast };
