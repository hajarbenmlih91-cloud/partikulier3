import fs from 'node:fs';
import vm from 'node:vm';

const SCENARIO = process.env.SERIE_E_SCENARIO || 'e1_redirect_url';
const COOKIE_TRACE = process.env.SERIE_E_COOKIE_TRACE || '';
const BASE = (process.env.PK_BASE || 'https://uat.example.test').replace(/\/$/, '');

function recordCookieTrace(event) {
  if (!COOKIE_TRACE) return;
  let data = { cookie_value_read: false, accessed_keys: [] };
  try {
    data = JSON.parse(fs.readFileSync(COOKIE_TRACE, 'utf8'));
  } catch { /* init */ }
  if (event === 'value') data.cookie_value_read = true;
  if (!data.accessed_keys.includes(event)) data.accessed_keys.push(event);
  fs.writeFileSync(COOKIE_TRACE, JSON.stringify(data));
}

function makeCookieProxy(name, value) {
  const raw = { name, value, domain: new URL(BASE).hostname, path: '/' };
  return new Proxy(raw, {
    get(target, prop, receiver) {
      if (typeof prop === 'string') recordCookieTrace(prop);
      return Reflect.get(target, prop, receiver);
    },
    getOwnPropertyDescriptor(target, prop) {
      if (prop === 'value') recordCookieTrace('value');
      return Reflect.getOwnPropertyDescriptor(target, prop);
    },
  });
}

function createScenarioState() {
  return {
    phase: 'login',
    bannerVisible: SCENARIO !== 'e4_banner_absent',
    consentChosen: false,
    loggedIn: false,
    currentUrl: `${BASE}/fr/connexion/?auth_item=login-form&redirect_url=${encodeURIComponent(`${BASE}/fr/mes-annonces/`)}`,
  };
}

function makeDocument(state) {
  const isDashboard = state.phase === 'dashboard';
  const hasDashboard = isDashboard && SCENARIO !== 'e3_dashboard_absent';
  const hasEmpty = isDashboard && SCENARIO !== 'e5_empty_absent';
  const titleText = 'Bonjour pk_audit_test,\nvos annonces sont en ligne.';
  const bodyText = isDashboard
    ? `${titleText}\nAucune annonce publiée pour le moment.`
    : 'Connexion propriétaire';

  return {
    body: {
      innerText: bodyText,
      classList: {
        contains(cls) {
          if (cls === 'pk-cookie-open') return state.bannerVisible;
          return false;
        },
      },
    },
    querySelector(selector) {
      if (selector === 'h1') {
        return { innerText: isDashboard ? titleText : 'Connexion' };
      }
      if (selector === '.pk-auth-card input[type="password"]') {
        return isDashboard ? null : { tagName: 'INPUT' };
      }
      if (selector === '.pk-dashboard') {
        return hasDashboard ? { className: 'pk-dashboard' } : null;
      }
      if (selector === '.pk-dashboard-title') {
        return isDashboard ? { innerText: titleText } : null;
      }
      if (selector === '.pk-dashboard-empty') {
        return hasEmpty ? { className: 'pk-dashboard-empty' } : null;
      }
      return null;
    },
  };
}

function dashboardUrlForScenario() {
  switch (SCENARIO) {
    case 'e1_redirect_url':
      return `${BASE}/fr/mes-annonces/?redirect_url=%2Ffr%2Fdeposer%2F&source=audit#onglet`;
    case 'e2_no_params':
      return `${BASE}/mes-annonces`;
    default:
      return `${BASE}/fr/mes-annonces/`;
  }
}

function makeLocator(state, kind, meta = {}) {
  const loc = {
    first() {
      return loc;
    },
    async count() {
      if (kind === 'email' || kind === 'password' || kind === 'submit') return 1;
      if (kind === 'consent_button') return state.bannerVisible ? 1 : 0;
      if (kind === 'cookie_bar') return state.bannerVisible ? 1 : 0;
      return 0;
    },
    async isVisible() {
      if (kind === 'cookie_bar') return state.bannerVisible;
      if (kind === 'consent_button') return state.bannerVisible;
      return true;
    },
    async isEnabled() {
      if (kind === 'consent_button') return state.bannerVisible;
      return true;
    },
    async fill() {
      return undefined;
    },
    async innerText() {
      if (kind === 'submit') return 'Connexion';
      if (kind === 'consent_button') return meta.label || '';
      return '';
    },
    async waitFor({ state: targetState } = {}) {
      if (kind === 'cookie_bar') {
        if (targetState === 'visible') {
          if (!state.bannerVisible) throw new Error('locator.waitFor: Timeout 5000ms exceeded');
          return undefined;
        }
        if (targetState === 'hidden') {
          if (state.bannerVisible) throw new Error('locator.waitFor: Timeout 8000ms exceeded (still visible)');
          return undefined;
        }
      }
      return undefined;
    },
    async click(opts = {}) {
      if (kind === 'consent_button') {
        if (!state.bannerVisible) throw new Error('Consent button not visible');
        state.consentChosen = true;
        state.bannerVisible = false;
        return undefined;
      }
      if (kind === 'submit') {
        if (state.bannerVisible) {
          throw new Error('Element intercepts pointer events: .pk-cookie-bar');
        }
        if (opts && opts.trial) return undefined;
        state.loggedIn = true;
        return undefined;
      }
      return undefined;
    },
  };
  return loc;
}

export const chromium = {
  async launch() {
    return {
      async newContext() {
        const state = createScenarioState();
        return {
          request: {
            async get() {
              return { status: () => 401 };
            },
            async post() {
              return { status: () => 401 };
            },
          },
          async cookies() {
            if (!state.loggedIn) return [];
            return [makeCookieProxy('wordpress_logged_in_audit999', 'CANARY_COOKIE_SECRET_VALUE_987654')];
          },
          async newPage() {
            return {
              on() {},
              async goto(url) {
                if (url.includes('/connexion/')) {
                  state.phase = 'login';
                  state.currentUrl = url;
                  if (SCENARIO !== 'e4_banner_absent' && !state.consentChosen) {
                    state.bannerVisible = true;
                  }
                } else if (url.includes('/mes-annonces')) {
                  state.phase = 'dashboard';
                  state.currentUrl = dashboardUrlForScenario();
                }
                return { status: () => 200 };
              },
              async reload() {
                if (SCENARIO !== 'e4_banner_absent' && !state.consentChosen) {
                  state.bannerVisible = true;
                }
                return { status: () => 200 };
              },
              async waitForTimeout() {
                return undefined;
              },
              async screenshot() {
                return undefined;
              },
              locator(selector) {
                if (selector === '.pk-cookie-bar') return makeLocator(state, 'cookie_bar');
                if (selector.includes('email')) return makeLocator(state, 'email');
                if (selector.includes('password')) return makeLocator(state, 'password');
                if (selector.includes('submit')) return makeLocator(state, 'submit');
                return makeLocator(state, 'generic');
              },
              getByRole(role, { name } = {}) {
                if (role === 'button') {
                  return makeLocator(state, 'consent_button', { label: name });
                }
                return makeLocator(state, 'generic');
              },
              async evaluate(fn) {
                const sandbox = {
                  location: { href: state.currentUrl },
                  document: makeDocument(state),
                };
                return vm.runInNewContext(`(${fn.toString()})()`, sandbox);
              },
            };
          },
          async close() {
            return undefined;
          },
        };
      },
      async close() {
        return undefined;
      },
    };
  },
};
