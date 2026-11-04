(() => {
  'use strict';

  const app = document.getElementById('portal-app');
  const dialog = document.getElementById('portal-dialog');
  const page = document.body.dataset.page;
  const state = {
    user: null, csrf: '', ready: false, boot: {}, summary: null, admin: null,
    packages: [], stripeReady: false, stripeCheckoutEnabled: true, opsView: 'setup', opsPage: 1, opsEmail: '', opsLoad: 0, hostedPage: 1, hostedLoad: 0, analyticsLoad: 0, interval: 'monthly', hosting: 'cloud', tab: 'overview',
    token: new URLSearchParams(location.hash.slice(1)).get('token') || ''
  };
  if (['verify', 'reset'].includes(page) && location.hash) {
    history.replaceState(null, '', location.pathname + location.search);
  }
  const escape = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
  const iconPaths = {
    arrow: '<path d="M4 12h16M14 6l6 6-6 6"/>',
    check: '<path d="m5 12 4 4L19 6"/>',
    grid: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    shop: '<path d="M3 9h18l-2-5H5L3 9Zm1 0v11h16V9M9 20v-7h6v7"/>',
    card: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h3"/>',
    lock: '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/>',
    cloud: '<path d="M7 18h11a4 4 0 0 0 .2-8 6.5 6.5 0 0 0-12.5-1A4.5 4.5 0 0 0 7 18Z"/>',
    server: '<rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><path d="M7 6.5h.01M7 17.5h.01M11 6.5h6M11 17.5h6"/>',
    mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>',
    users: '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M17 5a3 3 0 0 1 0 6M18 15a4 4 0 0 1 3 4v2"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    logout: '<path d="M9 4H4v16h5M9 12h12M16 7l5 5-5 5"/>',
    copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V4H4v12h4"/>',
    eye: '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
    close: '<path d="m6 6 12 12M6 18 18 6"/>'
  };
  const icon = name => `<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${iconPaths[name] || iconPaths.grid}</svg>`;
  const brand = '<a class="brand" href="index.html" aria-label="Food Monster home"><img src="assets/monster.svg" width="36" height="36" alt=""><span>food<span>monster</span><b>.</b></span></a>';
  const hostingName = value => value === 'self_hosted' ? 'Self-hosted' : 'Food Monster Cloud';
  const statusNames = {pending_review:'Pending review',checkout_expired:'Checkout expired',active:'Active',expired:'Expired',suspended:'Suspended',reserved:'Reserved',verification_pending:'Awaiting verification',verified:'Domain verified',provisioning:'Being installed',failed:'Needs attention',released:'Released'};
  const badge = status => `<span class="badge ${['active','verified'].includes(status) ? 'positive' : ['suspended','expired','failed','revoked','withdrawn'].includes(status) ? 'negative' : 'pending'}">${escape(statusNames[status] || status)}</span>`;
  const expiryDate = value => value ? new Intl.DateTimeFormat(undefined,{year:'numeric',month:'short',day:'numeric',timeZone:'UTC'}).format(new Date(Number(value) * 1000)) : 'Not set';
  const date = value => value ? new Intl.DateTimeFormat(undefined,{year:'numeric',month:'short',day:'numeric'}).format(new Date(Number(value) * 1000)) : 'Not set';
  const money = (cents, currency) => {
    if (cents === null || cents === undefined) return 'Unavailable';
    try { return new Intl.NumberFormat(undefined,{style:'currency',currency:currency || 'USD',maximumFractionDigits:Number(cents) % 100 === 0 ? 0 : 2}).format(Number(cents) / 100); }
    catch { return `${escape(currency || '')} ${(Number(cents) / 100).toFixed(2)}`; }
  };
  const empty = (title, description, action = '', compact = false) => `<div class="empty-state${compact ? ' compact' : ''}"><span class="empty-icon">${icon('grid')}</span><h3>${escape(title)}</h3><p>${escape(description)}</p>${action}</div>`;
  const button = (text, attrs = '', type = 'secondary') => `<button class="button ${type}" ${attrs}>${escape(text)}</button>`;
  const field = (id, label, options = {}) => {
    const {type = 'text', value = '', required = true, autocomplete = '', help = '', attrs = '', name = id} = options;
    return `<div class="field"><label for="${id}">${label}</label><input id="${id}" name="${name}" type="${type}" value="${escape(value)}" ${required ? 'required' : ''} ${autocomplete ? `autocomplete="${autocomplete}"` : ''} ${help ? `aria-describedby="${id}-hint"` : ''} ${attrs}>${help ? `<small id="${id}-hint">${help}</small>` : ''}</div>`;
  };
  const passwordField = (id, label = 'Password', autocomplete = 'new-password', help = 'Use at least 12 characters. A longer, unique passphrase works well.') => `<div class="field"><label for="${id}">${label}</label><div class="password-wrap"><input id="${id}" name="${id}" type="password" required ${autocomplete !== 'current-password' ? 'minlength="12"' : ''} autocomplete="${autocomplete}" ${help ? `aria-describedby="${id}-hint"` : ''}><button class="password-toggle" type="button" data-password="${id}" aria-label="Show ${label.toLowerCase()}" aria-pressed="false">${icon('eye')}</button></div>${help ? `<small id="${id}-hint">${help}</small>` : ''}</div>`;
  const formMessage = '<div class="feedback" data-feedback role="alert" hidden></div>';
  const unavailableText = 'Account services are not connected in this preview. These pages become live when the PHP backend is installed and configured on your server.';

  async function api(action, body, query = {}) {
    const multipart = body instanceof FormData;
    let response;
    try {
      response = await fetch(`api/index.php?${new URLSearchParams({action,...query})}`, {
        method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: body === undefined ? {'Accept':'application/json'} : {'Accept':'application/json','X-CSRF-Token':state.csrf,...(multipart ? {} : {'Content-Type':'application/json'})},
        ...(body === undefined ? {} : {body:multipart ? body : JSON.stringify(body)})
      });
    } catch { throw new Error('We could not reach account services. Check your connection and try again.'); }
    if (!response.headers.get('content-type')?.includes('application/json')) {
      if (multipart) throw new Error('The host could not receive the ZIP. Try Use staged ZIP if it exceeds the hosting upload limit.');
      const error = new Error(unavailableText); error.unavailable = true; throw error;
    }
    let result;
    try { result = await response.json(); } catch { throw new Error('Account services returned an unreadable response. Please try again.'); }
    if (!response.ok || !result.ok) {
      const error = new Error(result.error?.message || 'Something went wrong. Please try again.');
      error.code = result.error?.code; error.fields = result.error?.fields; error.status = response.status;
      if (response.status === 419 && action !== 'bootstrap') {
        try { await bootstrap(); error.message = 'Your security session was refreshed. Please submit the form again.'; } catch {}
      }
      throw error;
    }
    if (result.data?.csrf) state.csrf = result.data.csrf;
    return result.data || {};
  }
  async function bootstrap() {
    state.boot = await api('bootstrap');
    state.csrf = state.boot.csrf;
    state.user = state.boot.user || null;
    state.ready = true;
  }
  function toast(message) {
    const el = document.getElementById('portal-toast');
    el.textContent = message; el.classList.add('show');
    clearTimeout(toast.timer); toast.timer = setTimeout(() => el.classList.remove('show'), 5000);
  }
  function showFeedback(form, message, kind = '') {
    const el = form.querySelector('[data-feedback]');
    if (!el) return toast(message);
    el.className = `feedback ${kind}`; el.textContent = message; el.hidden = false;
  }
  async function submit(form, work) {
    if (form.dataset.busy) return;
    form.dataset.busy = 'true'; form.setAttribute('aria-busy','true');
    const controls = [...form.querySelectorAll('button[type="submit"], button:not([type])')];
    controls.forEach(el => { el.disabled = true; });
    form.querySelector('[data-feedback]')?.setAttribute('hidden','');
    try { await work(); }
    catch (error) { showFeedback(form,error.message); }
    finally { delete form.dataset.busy; form.removeAttribute('aria-busy'); controls.forEach(el => { el.disabled = false; }); }
  }
  function formData(form) { return Object.fromEntries(new FormData(form)); }
  function header({label = '',auth = false} = {}) {
    const signedIn = state.user;
    return `<header class="portal-header">${brand}${label ? `<span class="small-brand-label">${escape(label)}</span>` : ''}<nav aria-label="Account navigation">${!auth ? '<a class="quiet-link" href="plans.html">Packages</a>' : ''}${signedIn ? `<a class="quiet-link" href="account.html">My account</a>${signedIn.role === 'admin' ? '<a class="quiet-link" href="admin.html">Admin</a>' : ''}<button class="header-logout" type="button" data-action="logout" aria-label="Log out">${icon('logout')}</button><span class="avatar" title="${escape(signedIn.name)}">${escape((signedIn.name || 'F').slice(0,1).toUpperCase())}</span>` : `<a class="quiet-link" href="${auth ? 'index.html' : 'login.html'}">${auth ? 'Back to website ↗' : 'Log in'}</a>`}</nav></header>`;
  }
  function serviceMessage() {
    if (!state.ready) return `<div class="auth-service-message" role="status">${icon('server')}<p>${escape(state.bootError || unavailableText)}</p></div>`;
    if (state.boot.mail_available === false) return '<div class="auth-service-message" role="status"><p>Email delivery has not been configured yet. Verification and recovery emails will be available after the site owner finishes setup.</p></div>';
    return '';
  }
  function authShell(content, title = 'A little less admin.<br> <span>A lot more restaurant.</span>', eyebrow = 'YOUR RESTAURANT, CONNECTED') {
    app.innerHTML = `${header({auth:true})}<main class="auth-layout" id="portal-main"><section class="auth-story"><div class="eyebrow"><span class="status-dot"></span>${eyebrow}</div><h1>${title}</h1><p>One Food Monster account for your subscription, restaurant installation, and hosting choice.</p><div class="auth-benefits"><div>${icon('check')}<span>Your own Gelato restaurant workspace</span></div><div>${icon('check')}<span>Food Monster Cloud or your own server</span></div><div>${icon('check')}<span>Packages and installation details in one place</span></div></div><div class="story-bottom"><img class="auth-monster" src="assets/monster.svg" width="62" height="62" alt=""><span class="auth-footnote">GOOD FOOD. LESS FRICTION.</span></div></section><section class="auth-card" aria-label="${escape(document.title)}">${content}${serviceMessage()}</section></main><footer class="auth-footer"><span>© ${new Date().getFullYear()} Food Monster</span><span>Cloud hosted. Self-hosted. Your choice.</span></footer>`;
  }
  function authSuccess(title, description, action = '<a class="button primary wide" href="login.html">Go to log in '+icon('arrow')+'</a>') {
    authShell(`<div class="success-symbol">${icon('check')}</div><h2>${escape(title)}</h2><p class="description">${escape(description)}</p>${action}`);
  }
  function renderAuth() {
    const disabled = state.ready && (state.boot.mail_available !== false || (page !== 'forgot' && (page !== 'signup' || state.boot.verification_required === false))) ? '' : 'disabled';
    if (page === 'signup') {
      authShell(`<div class="card-eyebrow">LET’S GET YOU SET UP</div><h2>Create your account.</h2><p class="description">Your next chapter starts here. Set up your account, then choose a package and where to host.</p><form id="signup-form">${formMessage}${field('name','Your name',{autocomplete:'name',attrs:'maxlength="120"'})}${field('business_name','Business name',{autocomplete:'organization',attrs:'maxlength="160"'})}${field('email','Email address',{type:'email',autocomplete:'email',attrs:'maxlength="254"'})}${passwordField('password')}<button class="button primary wide" type="submit" ${disabled}>Create account ${icon('arrow')}</button><p class="form-note">We’ll send you an email to verify your address. No card details are needed to create an account.</p></form><p class="form-footer">Already part of the crew? <a class="inline-link" href="login.html">Log in</a></p>`);
      document.getElementById('signup-form').addEventListener('submit', event => {event.preventDefault(); submit(event.target,async () => {
        const data = await api('auth.signup',formData(event.target));
        authSuccess(state.boot.mail_available === false ? 'Sign in to continue.' : 'Check your inbox.',data.message);
      });});
    } else if (page === 'login') {
      authShell(`<div class="card-eyebrow">WELCOME BACK</div><h2>Good to see you.</h2><p class="description">Log in to manage your Food Monster account.</p>${state.user ? `<div class="feedback success">You are signed in as ${escape(state.user.name)}. <a class="inline-link" href="account.html">Open your account</a>.</div>` : ''}<form id="login-form">${formMessage}${field('email','Email address',{type:'email',autocomplete:'username'})}${passwordField('password','Password','current-password','')}<div class="form-row"><span class="form-note">Your restaurant starts here.</span><a class="inline-link" href="forgot-password.html">Forgot password?</a></div><button class="button primary wide" type="submit" ${disabled}>Log in ${icon('arrow')}</button></form><p class="form-footer">New to Food Monster? <a class="inline-link" href="signup.html">Create an account</a></p>`);
      document.getElementById('login-form').addEventListener('submit', event => {event.preventDefault(); submit(event.target,async () => {
        const data = await api('auth.login',formData(event.target));
        const adminNext = new URLSearchParams(location.search).get('next') === 'admin' && data.user?.role === 'admin';
        location.assign(adminNext ? 'admin.html' : 'account.html');
      });});
    } else if (page === 'forgot') {
      authShell(`<div class="card-eyebrow">LET’S GET YOU BACK IN</div><h2>Forgot your password?</h2><p class="description">It happens. Enter your email and we’ll send instructions to reset it.</p><form id="forgot-form">${formMessage}${field('email','Email address',{type:'email',autocomplete:'email'})}<button class="button primary wide" type="submit" ${disabled}>Send reset link ${icon('arrow')}</button></form><p class="form-footer"><a class="inline-link" href="login.html">← Back to log in</a></p>`,'A fresh start.<br> <span>Same great restaurant.</span>','ACCOUNT RECOVERY');
      document.getElementById('forgot-form').addEventListener('submit',event => {event.preventDefault(); submit(event.target,async () => {
        const data = await api('auth.forgot',formData(event.target));
        authSuccess('Check your inbox.',data.message || 'If an account matches that email, you will receive a password reset link.');
      });});
    } else if (page === 'reset') {
      authShell(`<div class="card-eyebrow">A FRESH START</div><h2>Choose a new password.</h2><p class="description">Make it unique to your Food Monster account.</p>${state.token ? `<form id="reset-form">${formMessage}${passwordField('password','New password')}${passwordField('confirm_password','Confirm new password','new-password','')}<button class="button primary wide" type="submit" ${disabled}>Reset password ${icon('arrow')}</button></form>` : '<div class="feedback info">Open the full reset link from your email to continue.</div><a class="button secondary wide" href="forgot-password.html">Request a new link</a>'}<p class="form-footer"><a class="inline-link" href="login.html">← Back to log in</a></p>`,'Back in control.<br> <span>Back to your business.</span>','ACCOUNT RECOVERY');
      document.getElementById('reset-form')?.addEventListener('submit',event => {event.preventDefault(); submit(event.target,async () => {
        const values = formData(event.target);
        if (values.password !== values.confirm_password) throw new Error('The passwords do not match. Please try again.');
        await api('auth.reset',{token:state.token,password:values.password}); state.token = ''; await bootstrap();
        authSuccess('Password updated.','Your new password is ready. Log in again to continue.');
      });});
    } else if (page === 'verify') {
      authShell(`<div class="card-eyebrow">ONE QUICK CHECK</div><h2>Verify your email.</h2><p class="description">Confirm your email address to choose a subscription and register your restaurant.</p>${state.token ? `<form id="verify-form">${formMessage}<button class="button primary wide" type="submit" ${disabled}>Verify email address ${icon('check')}</button></form>` : `<div class="feedback info">Open the verification link from your email to confirm your address.</div><a class="button secondary wide" href="account.html">Go to my account</a>`}<p class="form-note">Need another link? Log in to your account to resend your verification email.</p>`,'Make it official.<br> <span>You’re almost in.</span>','EMAIL VERIFICATION');
      document.getElementById('verify-form')?.addEventListener('submit',event => {event.preventDefault(); submit(event.target,async () => {
        await api('auth.verify',{token:state.token}); state.token = '';
        authSuccess('Email verified.','You’re ready to choose a package and set up your restaurant.',`<a class="button primary wide" href="account.html">Continue to my account ${icon('arrow')}</a>`);
      });});
    }
  }
  function renderUnavailable() {
    app.innerHTML = `${header()}<main id="portal-main" class="service-unavailable"><div class="eyebrow">ACCOUNT SERVICES</div><h1>Your account starts here.</h1><p>${escape(state.bootError || unavailableText)}</p><div class="card-actions"><a class="button primary" href="signup.html">View sign-up page ${icon('arrow')}</a><a class="button secondary" href="plans.html">View packages</a></div><div class="preview-note">The customer dashboard and admin area load real account data after server setup.</div></main>`;
  }
  function shell(content, admin = false) {
    const tabs = admin ? [['operations','grid','Operations'],['packages','grid','Packages'],['subscriptions','card','Subscriptions'],['installations','server','Installations'],['subdomains','cloud','Subdomains'],['licenses','lock','Licenses'],['releases','grid','Releases'],['updates','arrow','Updates'],['analytics','grid','Update analytics'],['cpanel','server','cPanel'],['hosting','cloud','Hosting jobs'],['customers','users','Customers']] : [['overview','grid','Overview'],['setup','cloud','Cloud setup'],['restaurant','shop','My restaurant'],['subscription','card','Subscription'],['security','lock','Security']];
    app.innerHTML = `${header({label:admin ? 'ADMIN' : 'MY ACCOUNT'})}<div class="portal-shell"><aside class="portal-sidebar" aria-label="${admin ? 'Admin' : 'Account'} sections"><div class="sidebar-eyebrow">${admin ? 'PLATFORM' : 'YOUR WORKSPACE'}</div><nav class="portal-tabs">${tabs.map(([key,ico,label])=>`<button type="button" class="portal-tab${state.tab === key ? ' active' : ''}" data-tab="${key}" ${state.tab === key ? 'aria-current="page"' : ''}>${icon(ico)}<span>${label}</span></button>`).join('')}</nav><div class="sidebar-bottom"><span class="sidebar-eyebrow">FOOD MONSTER</span><p>${admin ? 'Subscriptions. Hosting. One home.' : 'Your business. Your hosting choice.'}</p><a href="index.html" class="quiet-link">Back to website ↗</a></div></aside><main class="portal-main" id="portal-main">${admin ? verifyBanner() : ''}${content}</main></div>`;
  }
  const title = (eyebrow, heading, description, action = '') => `<div class="portal-title"><div><div class="eyebrow">${escape(eyebrow)}</div><h1>${escape(heading)}</h1><p>${escape(description)}</p></div>${action}</div>`;
  const verificationSatisfied = () => state.user.email_verified || state.boot.verification_required === false;
  function verifyBanner() {
    return verificationSatisfied() ? '' : `<div class="status-banner warning">${icon('mail')}<div><strong>Verify your email to get started.</strong><p>Check ${escape(state.user.email)} for your verification link.</p></div><button type="button" class="text-button" data-action="resend">Resend email</button></div>`;
  }
  const currentSubscriptions = () => (state.summary?.subscriptions || []).filter(item => item.status === 'active');
  function renderOverview() {
    const data = state.summary;
    const active = currentSubscriptions();
    const installations = data.installations.filter(item => item.status !== 'released');
    const firstName = (state.user.name || '').trim().split(/\s+/)[0];
    const done = [verificationSatisfied(),active.length > 0,installations.length > 0];
    return title('YOUR FOOD MONSTER ACCOUNT',`Welcome, ${firstName}.`,'A home for your restaurant’s next chapter.') + verifyBanner() + `<div class="portal-summary"><div class="summary-tile"><span>ACCOUNT</span><strong>${state.user.email_verified ? 'Verified' : state.boot.verification_required === false ? 'Email optional' : 'Check your email'}</strong><small>${escape(state.user.email)}</small></div><div class="summary-tile"><span>SUBSCRIPTIONS</span><strong>${active.length} active</strong><small>${data.subscriptions.some(s=>s.status==='pending_review') ? 'Request awaiting review' : 'Manage your package below'}</small></div><div class="summary-tile"><span>RESTAURANTS</span><strong>${installations.length} registered</strong><small>Cloud or self-hosted</small></div></div><div class="panel-grid"><section class="panel"><div class="panel-header"><div><h2>Your setup, step by step.</h2><p>Three small steps to your restaurant workspace.</p></div><span class="badge">${done.filter(Boolean).length}/3</span></div>${[[state.boot.verification_required === false ? 'Account created' : 'Verify your email',state.boot.verification_required === false ? 'You can continue with your account.' : 'Confirm the address you use for Food Monster.',verificationSatisfied() ? '' : '<button class="text-button" type="button" data-action="resend">Send verification email</button>'],['Choose a subscription','Pick a package for your business and hosting choice.','<a class="inline-link" href="plans.html">Explore packages →</a>'],['Register your restaurant','Reserve a cloud subdomain or connect your own domain.','<button class="text-button" type="button" data-tab="setup">Set up my restaurant →</button>']].map(([heading,desc,action],index)=>`<div class="setup-step"><span class="setup-marker${done[index] ? ' done' : ''}">${done[index] ? icon('check') : index+1}</span><div><h3>${heading}</h3><p>${desc}</p>${action}</div></div>`).join('')}</section><section class="panel"><div class="panel-header"><div><h2>Your subscription</h2><p>Software, with a hosting choice that fits.</p></div></div>${active.length ? active.slice(0,2).map(item=>subscriptionCard(item)).join('') : empty('Make room for your next chapter.','Your active package will appear here once your request is approved.','<a class="button secondary" href="plans.html">Explore packages '+icon('arrow')+'</a>',true)}</section></div><section class="panel"><div class="panel-header"><div><h2>Your restaurants</h2><p>Every location has its own Gelato installation.</p></div><button class="text-button" type="button" data-tab="restaurant">Manage →</button></div>${installations.length ? installations.map(item=>`<div class="record-row"><div><strong>${escape(item.business_name)}</strong><p>${escape(item.domain)} · ${hostingName(item.hosting_mode)}</p></div>${badge(item.status)}</div>`).join('') : empty('Your restaurant belongs here.','Choose your package, then register your first installation.','',true)}</section>`;
  }
  const intervalLabel = {monthly:'month',quarterly:'3 months',annual:'year'};
  const billingLabels = {checkout:'Awaiting checkout',confirming:'Awaiting payment',active:'Recurring billing active',
    ending:'Ends after paid period',past_due:'Payment needs attention',cancelled:'Billing cancelled',expired:'Checkout expired'};
  const paidCloudPlan = item => state.stripeReady && state.hosting === 'cloud' && item[`${state.interval}_price_cents`] > 0;
  const checkoutPaused = item => paidCloudPlan(item) && !state.stripeCheckoutEnabled;
  function subscriptionCard(item, admin = false) {
    const stripe = item.billing_provider === 'stripe';
    const status = stripe && item.status === 'pending_review' ? billingLabels[item.billing_status] : item.status;
    const billing = stripe && !admin && state.summary?.stripe_ready
      ? item.can_manage_billing ? recordButton('Manage billing','stripe-portal',item.id)
        : item.billing_status === 'checkout' ? recordButton('Continue checkout','stripe-resume',item.id) + recordButton('Recover checkout','stripe-recover',item.id) : ''
      : '';
    return `<div class="record-row"><div><strong>${escape(item.package_name)}</strong><p>${hostingName(item.hosting_mode)} · ${money(item.price_cents,item.currency)} / ${intervalLabel[item.billing_interval]}</p>${admin ? `<p>${escape(item.customer_name)} · ${escape(item.customer_email)}</p>` : ''}<small>${item.expires_at ? `Access through ${expiryDate(item.expires_at)} (UTC)` : `Requested ${date(item.created_at)}`}</small>${item.activation_reference ? `<p>Reference: ${escape(item.activation_reference)}</p>` : ''}${item.suspension_reason ? `<p>${escape(item.suspension_reason)}</p>` : ''}</div><div class="record-actions">${badge(status)}${billing}${stripe && item.status !== 'pending_review' ? `<small>${escape(billingLabels[item.billing_status])}</small>` : ''}${admin && !stripe ? `<div class="card-actions">${button(item.status === 'active' ? 'Renew' : 'Activate',`data-action="activate-subscription" data-id="${escape(item.id)}"`,'secondary small')}${['active','pending_review'].includes(item.status) ? button('Suspend',`data-action="suspend-subscription" data-id="${escape(item.id)}"`,'danger small') : ''}</div>` : ''}</div></div>`;
  }
  function hostedFields() {
    const names = state.user.name.trim().split(/\s+/);
    const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    return `<div class="field-row">${field('first_name','Owner first name',{value:names[0] || '',autocomplete:'given-name',attrs:'maxlength="60"'})}
      ${field('last_name','Owner last name',{value:names.slice(1).join(' '),autocomplete:'family-name',attrs:'maxlength="60"'})}</div>
      ${field('owner_email','Owner email',{value:state.user.email,type:'email',attrs:'readonly',help:'Uses your Food Monster account email.'})}
      ${field('location_name','First location',{value:'Main Location',attrs:'maxlength="120"'})}
      ${field('timezone','Timezone',{value:timezone,attrs:'maxlength="80"',help:'Use a timezone like America/Phoenix.'})}
      ${field('legal_name','Legal business name',{required:false,attrs:'maxlength="160"'})}
      ${passwordField('owner_password','Gelato password','new-password','Use a separate password with at least 12 characters, including uppercase, lowercase, a number and a symbol.')}
      ${passwordField('owner_password_confirm','Confirm Gelato password','new-password','')}`;
  }

  function hostedProgress(item, admin) {
    const setup = item.hosted_setup;
    if (!setup) return '';
    const labels = {queued:'Setup queued',working:'Setting up Gelato',awaiting_https:'Checking HTTPS and Gelato health',retry_wait:'Setup will retry',failed:'Setup needs attention',cancelled:'Setup cancelled',ready:'Your Gelato site is ready'};
    return `<p class="form-note" role="status">${escape(labels[setup.state] || 'Setup in progress')}</p>${admin ? infoRows([['Installation ID',item.id]]) : ''}`;
  }

  function renderRestaurant() {
    const data = state.summary;
    const active = currentSubscriptions();
    const modes = [...new Set(active.map(item=>item.hosting_mode))];
    if (modes.length && !modes.includes(state.hosting)) state.hosting = modes[0];
    const canRegister = verificationSatisfied() && modes.length > 0;
    const managed = data.hosted_setup_ready && state.hosting === 'cloud';
    const max = active.filter(item=>item.hosting_mode===state.hosting).reduce((sum,item)=>sum+Number(item.max_installations || 0),0);
    return title('YOUR RESTAURANT','A workspace of your own.','Manage your business details and where your Gelato installation lives.') + verifyBanner() + incomingTransfers(data.subdomain_transfers) + `<div class="panel-grid"><section class="panel"><div class="panel-header"><div><h2>Business details</h2><p>How we know you and your restaurant.</p></div></div><form id="profile-form">${formMessage}${field('name','Your name',{value:state.user.name,autocomplete:'name',attrs:'maxlength="120"'})}${field('business_name','Business name',{value:data.business?.business_name || '',autocomplete:'organization',attrs:'maxlength="160"'})}${field('profile_email','Email address',{value:state.user.email,type:'email',attrs:'readonly',help:'Your sign-in address.'})}<button class="button secondary" type="submit">Save details</button></form></section><section class="panel"><div class="panel-header"><div><h2>${managed ? 'Set up your Gelato site' : 'Register an installation'}</h2><p>Your own restaurant, your own space.</p></div></div>${canRegister ? `<form id="installation-form">${formMessage}<span class="block-label">Where will it live?</span><div class="hosting-switch">${modes.map(mode=>`<label class="hosting-radio"><input type="radio" name="hosting_mode" value="${mode}" ${state.hosting===mode ? 'checked' : ''}>${icon(mode === 'cloud' ? 'cloud' : 'server')}<strong>${mode==='cloud' ? 'Food Monster Cloud' : 'Your own server'}</strong><small>${mode==='cloud' ? 'Reserve your restaurant address' : 'Connect a domain you control'}</small></label>`).join('')}</div>${field('installation_business','Restaurant name',{name:'business_name',value:data.business?.business_name || '',attrs:'maxlength="160"'})}${managed ? hostedFields() : ''}${state.hosting==='cloud' ? `<div class="field"><label for="subdomain">Choose a subdomain</label><div class="domain-input"><input id="subdomain" name="subdomain" required minlength="3" maxlength="40" pattern="[a-z][a-z0-9-]{2,39}" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="your-restaurant" aria-describedby="subdomain-hint"><span>.${escape(data.base_domain || 'foodmonster.xyz')}</span></div><small id="subdomain-hint">3–40 lowercase letters, numbers, or single hyphens. Start with a letter.</small><button class="text-button" type="button" data-action="check-domain">Check availability</button><p class="availability" id="availability" role="status" hidden></p></div>` : field('domain','Your installation domain',{attrs:'autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="order.yourrestaurant.com"',help:'Enter a hostname, without https:// or a path. You’ll verify it with a DNS TXT record.'})}<p class="form-note">${state.hosting==='cloud' ? 'This reserves your address. Your hosting and Gelato setup are completed before the restaurant is marked active.' : 'You manage your server and installation. Domain verification confirms that you control this address.'}${max ? ` Your active package allows ${max} ${max === 1 ? 'installation' : 'installations'} for this hosting choice.` : ''}</p><button class="button primary" type="submit">${managed ? 'Submit site setup' : state.hosting==='cloud' ? 'Reserve subdomain' : 'Register domain'} ${icon('arrow')}</button></form>` : empty('Start with a subscription.','Activate a package to register your restaurant.','<a class="button secondary" href="plans.html">Explore packages</a>',true)}</section></div><section class="panel"><div class="panel-header"><div><h2>Registered installations</h2><p>Track your domain and setup status.</p></div></div>${data.installations.length ? data.installations.map(item=>installationCard(item)).join('') : empty('A new home for your restaurant.','Your registered installations will appear here.','',true)}</section>`;
  }
  function incomingTransfers(offers = []) {
    if (!offers.length) return '';
    return `<section class="panel"><div class="panel-header"><div><h2>Incoming subdomain transfers</h2><p>Accept a registration into an active Cloud plan with room for another site.</p></div></div>${offers.map(offer=>`<div class="record-row"><div><strong>${escape(offer.domain)}</strong><p>From ${escape(offer.sender_name)} · Offer ends ${expiryDate(offer.expires_at)} UTC</p></div><div class="card-actions">${recordButton('Accept','subdomain-accept',offer.id)}${recordButton('Decline','subdomain-decline',offer.id)}</div></div>`).join('')}</section>`;
  }
  function subdomainRegistration(item, admin) {
    const term = item.subdomain_registration;
    if (!term) return '';
    const controls = [];
    if (!admin && !term.transfer && Date.now() / 1000 >= term.renewal_opens_at) {
      controls.push(recordButton('Renew for one year','subdomain-renew',item.id));
    }
    if (term.can_transfer) controls.push(recordButton('Transfer subdomain','subdomain-offer',item.id));
    if (!admin && term.can_relink) controls.push(recordButton('Link active Cloud plan','subdomain-relink',item.id));
    if (!admin && term.transfer) controls.push(recordButton('Cancel transfer','subdomain-cancel',term.transfer.id));
    return `<p class="form-note"><strong>${term.state==='expired' ? 'Registration expired' : 'Registration expires'}</strong> ${expiryDate(term.expires_at)} UTC.
      ${term.transfer ? 'Transfer awaiting acceptance.' : term.state==='registered' ? `Renewal opens ${expiryDate(term.renewal_opens_at)} UTC.` : ''}</p>
      ${controls.length ? `<div class="card-actions">${controls.join('')}</div>` : ''}`;
  }
  function installationCard(item, admin = false) {
    const term = item.subdomain_registration;
    const available = !term?.transfer && term?.state !== 'expired';
    const showVerification = !term?.transfer && item.status !== 'released' && !item.verified_at && (item.hosting_mode === 'self_hosted' || admin);
    return `<article class="installation-card"><div class="installation-title"><div><span class="eyebrow">${hostingName(item.hosting_mode)}</span><h3>${escape(item.business_name)}</h3><p>${escape(item.domain)}</p>${admin ? `<p class="admin-customer-meta">${escape(item.customer_name)} · ${escape(item.customer_email)}</p>` : ''}</div>${badge(item.status)}</div>${subdomainRegistration(item,admin)}${hostedProgress(item,admin)}${item.hosting_mode === 'cloud' && ['reserved','verification_pending','verified'].includes(item.status) && !admin ? '<p class="form-note">Your address is reserved. Food Monster will complete the hosting setup and configure Gelato for your business.</p>' : ''}${item.status === 'provisioning' ? '<p class="form-note">Your installation is being prepared. It will appear active after setup is confirmed.</p>' : ''}${item.verified_at ? `<p class="form-note">${icon('check')} Domain ownership verified ${date(item.verified_at)}.</p>` : ''}${showVerification ? `<div class="verification-box"><h4>Verify domain ownership</h4><p>Add this TXT record at your DNS provider, then check verification. DNS changes can take time to become visible.</p><div class="dns-row"><span>TYPE</span><code class="dns-value">TXT</code></div><div class="dns-row"><span>NAME</span><code class="dns-value">${escape(item.verification_record)}</code><button class="copy-button" type="button" data-copy="${escape(item.verification_record)}" aria-label="Copy TXT record name">${icon('copy')}</button></div><div class="dns-row"><span>VALUE</span><code class="dns-value">${escape(item.verification_value)}</code><button class="copy-button" type="button" data-copy="${escape(item.verification_value)}" aria-label="Copy TXT record value">${icon('copy')}</button></div><p class="form-note">If your DNS provider adds the zone name automatically, enter only the record’s relative name.</p><button class="button secondary small" type="button" data-action="verify-domain" data-id="${escape(item.id)}">Check verification</button></div>` : ''}<div class="card-actions">${item.status==='active' && (!item.hosted_setup || item.hosted_setup.state==='ready') ? `<a class="button secondary small" href="https://${escape(item.domain)}" target="_blank" rel="noopener noreferrer">${item.hosted_setup ? 'Open your Gelato site' : 'Open restaurant'} ↗</a>` : ''}${!admin && available && state.summary?.hosted_setup_ready && item.status==='reserved' && item.hosting_mode==='cloud' && !item.hosted_setup && (!item.licensing || (item.licensing.configured_license_state==='active' && !item.licensing.activated)) ? recordButton('Set up Gelato','hosted-existing',item.id) : ''}${!admin && !term?.transfer && item.can_release && !item.hosted_setup ? button('Release registration',`type="button" data-action="release-domain" data-id="${escape(item.id)}"`,'danger small') : ''}${!admin && available && item.can_release && !item.hosted_setup && item.hosting_mode==='cloud' ? button('Rename subdomain',`type="button" data-action="rename-domain" data-id="${escape(item.id)}"`,'secondary small') : ''}${admin && !term?.transfer && item.status!=='released' && !item.hosted_setup ? button('Update status',`type="button" data-action="installation-status" data-id="${escape(item.id)}"`,'secondary small') : ''}</div>${licenseDetails(item, admin)}</article>`;
  }
  const updateStates = {
    available:'Update available',up_to_date:'Up to date',minimum_version:'An intermediate version is required',
    license_pending:'License pending',license_suspended:'Updates paused',license_expired:'Updates paused',
    license_revoked:'Updates paused',installation_inactive:'Installation setup pending',updates_excluded:'Updates excluded',
    activation_required:'Gelato activation required',version_unknown:'Installed version unknown',no_release:'No published release',
    managed_update_in_progress:'Hosted update in progress'
  };
  const updatePhases = {queued:'Queued for the hosting worker',swapping:'Deploying and checking the site',recovery:'Hosting operator recovery required'};
  const infoRows = rows => `<dl class="info-list">${rows.map(([label,value])=>`<div><dt>${escape(label)}</dt><dd>${escape(value)}</dd></div>`).join('')}</dl>`;
  const recordButton = (label,action,id) => button(label,`type="button" data-action="${action}" data-id="${escape(id)}"`,'secondary small');
  function licenseDetails(item, admin) {
    if (!Object.hasOwn(item,'licensing') || item.status === 'released') return '';
    const license = item.licensing;
    if (!license) return `<div class="subtle-divider"></div><p class="form-note">License awaiting issuance.</p>${admin && !item.hosted_setup ? recordButton('Issue license','license-issue',item.id) : ''}`;
    const last = license.last_update;
    const options = [
      ['Plan',license.plan],['License',statusNames[license.license_state] || license.license_state],
      ['Access through',expiryDate(license.expires_at)],['Gelato',license.activated ? 'Connected' : 'Awaiting connection'],
      ['Installed version',license.installed_version || 'Unknown'],['Latest release',license.latest_release?.version || 'None'],
      ['Update',updateStates[license.update_status] || license.update_status],
      ['Automatic updates',license.auto_update === 'off' ? 'Off' : license.auto_update + ' preference saved'],
      ['Last check-in',date(license.last_check_in)]
    ];
    if (last) options.push(['Last update',`${last.version} · ${last.state}${last.error_summary ? ' · ' + last.error_summary : ''}`],['Attempt ID',last.id]);
    if (license.managed_update) options.push(['Hosted update',updatePhases[license.managed_update.phase] || license.managed_update.phase]);
    const canKey = admin || (item.status === 'active' && item.verified_at && license.license_state === 'active');
    const controls = license.configured_license_state === 'revoked' ? '' : [
      admin ? recordButton('Manage license','license-manage',item.id) : '',
      canKey && !license.managed_update ? recordButton(license.activated ? 'Rotate activation key' : 'Generate activation key','license-key',item.id) : '',
      recordButton('Automatic update preference','license-preference',item.id),
      !admin && license.managed_update_available ? recordButton('Apply hosted update','release-apply',item.id) : '',
      license.available_release ? recordButton('Review manual update','release-download',item.id) : ''
    ].join('');
    return `<div class="subtle-divider"></div>${infoRows(options)}<div class="card-actions">${controls}</div>`;
  }
  function renderLicensingAdmin(data) {
    const section = state.tab;
    const headings = {subdomains:'Cloud subdomains',licenses:'Gelato licenses',releases:'Gelato releases',updates:'Update activity'};
    let control = '';
    if (section === 'subdomains') control = button('Add subdomain','type="button" data-action="subdomain-add"','primary');
    if (section === 'licenses' && data.licensing_ready) control = button('Create license','type="button" data-action="license-issue"','primary');
    if (section === 'releases' && data.licensing_ready) control = `<div class="card-actions">${button('Use staged ZIP','type="button" data-action="release-create"')}${button('Upload release ZIP','type="button" data-action="release-upload"')}${data.release_intake_ready ? button('Import signed bundle','type="button" data-action="release-bundle"','primary') : '<a href="upgrade.php">Enable signed bundle imports</a>'}</div>`;
    const heading = title('PLATFORM ADMIN',headings[section],'Manage licensed Gelato installations and software releases.',control);
    if (!data.licensing_ready && section !== 'subdomains') {
      return heading + `<section class="panel"><p>Run the database upgrade to enable licensing and the release registry.</p><a class="button primary" href="upgrade.php">Open database upgrade</a></section>`;
    }
    if (section === 'releases') {
      const hostedVersion = data.configuration?.hosted_release_version;
      const hosted = hostedVersion ? `<p class="form-note">New hosted installs use version ${escape(hostedVersion)} from the private hosting settings.</p>` : '<p class="form-note">Select a signed hosted release in the private hosting settings to enable new installs.</p>';
      return heading + `<section class="panel">${hosted}${data.releases.length ? data.releases.map(release=>`<article class="installation-card"><div class="installation-title"><h3>${escape(release.version)} · ${escape(release.channel)}</h3>${badge(release.status)}</div><p>${escape(release.notes)}</p>${infoRows([['Published',date(release.published_at)],['Minimum version',release.minimum_version || 'Any known version'],['Package bytes',release.package_bytes],['SHA-256',release.checksum],['Source commit',release.source_commit],['Signing key fingerprint',release.public_key_sha256],['Database upgrade',release.migration_required ? 'Required' : 'None'],['Automatic compatibility',release.automatic_compatible ? 'Yes' : 'Manual'],['Rollback',release.rollback_notes || 'None recorded']])}<div class="card-actions">${release.status === 'draft' ? recordButton('Publish','release-publish',release.id) : ''}${release.status !== 'withdrawn' ? recordButton('Withdraw','release-withdraw',release.id) : ''}</div></article>`).join('') : '<p>No releases have been drafted.</p>'}</section>`;
    }
    const items = section === 'subdomains' ? data.installations.filter(item=>item.hosting_mode==='cloud') :
      section === 'updates' ? data.installations.filter(item=>item.licensing?.last_update) : data.installations.filter(item=>item.status!=='released');
    return heading + `<section class="panel">${items.length ? items.map(item=>installationCard(item,true)).join('') : '<p>No matching installations yet.</p>'}</section>`;
  }
  const choicesField = (name,label,choices,value) => `<div class="field"><label for="${name}">${label}</label><select id="${name}" name="${name}">${choices.map(([key,text])=>`<option value="${escape(key)}" ${key===value?'selected':''}>${escape(text)}</option>`).join('')}</select></div>`;
  function showActivationKey(result, id) {
    dialog.innerHTML = `<div class="dialog-inner"><h2 id="dialog-title">Gelato activation key</h2><p>Copy the installation ID and key into the Gelato owner's License &amp; Updates page. The key is shown here once.</p>${infoRows([['Installation ID',id]])}<div class="field"><label for="issued-key">Activation key</label><input id="issued-key" readonly value="${escape(result.activation_key)}"></div><div class="dialog-actions">${button('Copy key',`type="button" data-copy="${escape(result.activation_key)}"`)}${button('Done','type="button" data-close-dialog','primary')}</div></div>`;
    dialog.addEventListener('close',()=>{ dialog.textContent=''; },{once:true});
    dialog.showModal();
  }
  async function handleLicensingAction(action,id) {
    const admin = page === 'admin';
    const reload = admin ? reloadAdmin : reloadAccount;
    const item = (admin ? state.admin : state.summary).installations.find(entry=>entry.id===id);
    const license = item?.licensing;
    if (action === 'subdomain-add') {
      const options = state.admin.subscriptions.filter(subscription=>subscription.status==='active' && subscription.hosting_mode==='cloud'
        && state.admin.installations.filter(item=>item.subscription_id===subscription.id && item.status!=='released').length < subscription.max_installations)
        .map(subscription=>[subscription.id,`${subscription.customer_name} · ${subscription.package_name}`]);
      if (!options.length) { toast('An active Cloud subscription with an available installation is required.'); return; }
      openDialog('Add subdomain',`Reserve a name under ${state.admin.configuration.base_domain}. Hosting setup follows reservation.`,
        choicesField('subscription_id','Customer subscription',options)+
        field('business_name','Restaurant name',{attrs:'minlength="2" maxlength="160"'})+
        field('subdomain','Subdomain',{attrs:'minlength="3" maxlength="40" placeholder="your-restaurant" autocomplete="off"'}),
        async values=>{
          const result = await api('admin.subdomain_add',values);
          await reloadAdmin(); toast(result.message);
        },'Reserve subdomain');
    } else if (action === 'rename-domain') {
      openDialog('Rename unused subdomain',item.domain,field('subdomain','New subdomain',{value:item.subdomain,attrs:'minlength="3" maxlength="40"'}),async values=>{
        await api('installations.rename',{installation_id:id,subdomain:values.subdomain}); await reload(); toast('Subdomain renamed.');
      },'Rename subdomain');
    } else if (action === 'license-key' || action === 'license-issue') {
      const issue = action === 'license-issue';
      let fields = '';
      if (issue && !id) {
        const options = state.admin.installations.filter(entry=>entry.status!=='released' && !entry.licensing && !entry.hosted_setup)
          .map(entry=>[entry.id,`${entry.business_name} · ${entry.domain}`]);
        if (!options.length) { toast('Add or register an installation that needs a license first.'); return; }
        fields = choicesField('installation_id','Installation',options);
      }
      openDialog(issue?'Create license':'Generate new activation key',issue?'Issue one license for this registered installation.':'A new key disconnects the existing Gelato connection. Restaurant operations continue.',fields,async values=>{
        const installationId = id || values.installation_id;
        const result = await api(admin?'admin.license_manage':'licenses.key',{installation_id:installationId,operation:issue?'issue':'rotate'});
        await reload();
        dialog.addEventListener('close',()=>showActivationKey(result,installationId),{once:true});
      },issue?'Create license':'Generate key');
    } else if (action === 'license-manage') {
      const operations = [['activate','Activate'],['deactivate','Deactivate'],['suspend','Suspend'],['revoke','Revoke permanently'],['renew','Change expiry'],['policy','Change update policy']];
      openDialog('Manage license',item.domain,choicesField('operation','Action',operations,license.configured_license_state==='active'?'deactivate':'activate')+
        field('expires_at','Expiry override',{type:'date',required:false,help:'For Change expiry only. A blank date follows the subscription. The subscription always caps access.'})+
        choicesField('channel','Update channel',[['stable','Stable'],['beta','Beta'],['development','Development']],license.channel)+
        `<label class="check-label"><input type="checkbox" name="updates_enabled" ${license.updates_enabled?'checked':''}> Include software updates</label>`,async values=>{
        await api('admin.license_manage',{installation_id:id,operation:values.operation,channel:values.channel,updates_enabled:values.updates_enabled==='on',expires_at:values.expires_at?Math.floor(Date.parse(values.expires_at+'T23:59:59Z')/1000):null});
        await reload(); toast('License updated.');
      },'Apply license change',true);
    } else if (action === 'license-preference') {
      const options = [['off','Off']];
      if (['stable','beta'].includes(license.channel) && license.updates_enabled) options.push([license.channel,license.channel==='stable'?'Stable':'Beta']);
      openDialog('Automatic update preference','Gelato records this preference for a future automatic updater.',choicesField('auto_update','Preference',options,license.auto_update),async values=>{
        await api('licenses.preference',{installation_id:id,auto_update:values.auto_update}); await reload(); toast('Preference saved.');
      });
    } else if (action === 'release-bundle') {
      const fields = '<div class="field"><label for="release-bundle">Signed Gelato artifact ZIP</label><input id="release-bundle" name="package" type="file" accept=".zip,application/zip" required></div>'+
        field('minimum_version','Minimum installed version',{required:false})+
        '<div class="field"><label for="rollback_notes">Rollback notes</label><textarea id="rollback_notes" name="rollback_notes" maxlength="5000"></textarea></div>'+
        '<label class="check-label"><input type="checkbox" name="migration_required"> Requires a database upgrade</label>'+
        '<label class="check-label"><input type="checkbox" name="automatic_compatible"> Compatible with automatic file updates</label>';
      openDialog('Import a signed Gelato release','Use the original signed artifact ZIP. Review the separate release notes and update settings before publishing.',fields,async (values,form)=>{
        await api('admin.release_bundle_upload',new FormData(form));
        await reload(); toast('Signed release verified and saved as a draft.');
      },'Verify and create draft');
    } else if (action === 'release-create' || action === 'release-upload') {
      const upload = action === 'release-upload';
      const fields = field('version','Version',{attrs:'placeholder="1.2.0"'})+
        choicesField('channel','Channel',[['stable','Stable'],['beta','Beta'],['development','Development']],'stable')+
        (upload ? '<div class="field"><label for="release-package">Gelato release ZIP</label><input id="release-package" name="package" type="file" accept=".zip,application/zip" required></div>'
          : field('package_name','Private ZIP filename',{attrs:'maxlength="174"'}))+
        field('minimum_version','Minimum installed version',{required:false})+
        '<div class="field"><label for="notes">Release notes</label><textarea id="notes" name="notes" maxlength="10000" required></textarea></div>'+
        '<div class="field"><label for="rollback_notes">Rollback notes</label><textarea id="rollback_notes" name="rollback_notes" maxlength="5000"></textarea></div>'+
        '<label class="check-label"><input type="checkbox" name="migration_required"> Requires a database upgrade</label>'+
        '<label class="check-label"><input type="checkbox" name="automatic_compatible"> Compatible with automatic file updates</label>';
      openDialog(upload?'Upload release ZIP':'Draft a staged release',
        upload?'Food Monster validates the ZIP and saves it as a private draft for review.':'Enter the filename already staged in the private release directory.',fields,async (values,form)=>{
          if (upload) {
            await api('admin.release_upload',new FormData(form));
          } else {
            await api('admin.release_create',{...values,migration_required:values.migration_required==='on',automatic_compatible:values.automatic_compatible==='on'});
          }
          await reload(); toast(upload?'ZIP validated and saved as a draft.':'Release drafted.');
        },upload?'Upload & create draft':'Create draft');
    } else if (action === 'release-publish' || action === 'release-withdraw') {
      const release = state.admin.releases.find(entry=>entry.id===id);
      const publish = action === 'release-publish';
      openDialog(publish?'Publish release?':'Withdraw release?',release.version+' · '+release.channel,'',async()=>{
        await api('admin.release_status',{release_id:id,status:publish?'published':'withdrawn'}); await reload(); toast(publish?'Release published.':'Release withdrawn.');
      },publish?'Publish release':'Withdraw release',!publish);
    } else if (action === 'release-apply') {
      const release = license.available_release;
      if (admin || !license.managed_update_available || !release) return;
      openDialog(`Apply Gelato ${release.version}?`,item.domain,
        `<p>${escape(release.notes)}</p><p class="form-note">The hosting worker keeps a private copy of your current application files, applies the update, and checks your site over HTTPS. It restores the previous version if the check fails. Your site may be briefly unavailable.</p>`,
        async () => {
          await api('licenses.apply_release',{installation_id:id,release_id:release.id});
          await reloadAccount();
          toast('Update queued for the hosting worker.');
        },'Approve & queue update');
    } else if (action === 'release-download') {
      const release = license.available_release;
      openDialog('Approve manual download',`Gelato ${release.version} · ${release.channel}`,
        `<p>${escape(release.notes)}</p>${infoRows([['SHA-256',release.checksum],['Database upgrade',release.migration_required ? 'Run Gelato upgrade.php after deployment' : 'None required'],['Rollback',release.rollback_notes || 'None recorded']])}`,()=>{
          const form = document.createElement('form');
          form.method='post'; form.action='api/release-download.php'; form.target='_blank'; form.rel='noopener';
          for (const [name,value] of Object.entries({csrf:state.csrf,installation_id:id,release_id:release.id})) {
            const input = document.createElement('input'); input.type='hidden'; input.name=name; input.value=value; form.append(input);
          }
          document.body.append(form); form.submit(); form.remove();
        },'Approve & download');
    }
  }
  function renderSubscription() {
    return title('YOUR SUBSCRIPTION','A package that fits.','View your requests, active subscriptions, and renewal dates.','<a class="button primary" href="plans.html">Explore packages '+icon('arrow')+'</a>') + verifyBanner() + `<section class="panel"><div class="panel-header"><div><h2>Subscriptions & requests</h2><p>New requests are reviewed before activation.</p></div></div>${state.summary.subscriptions.length ? state.summary.subscriptions.map(item=>subscriptionCard(item)).join('') : empty('Your next chapter is waiting.','Explore the available packages to get started.','<a class="button secondary" href="plans.html">Choose a package</a>')}<p class="form-note">Submitting a request does not charge a card. Payment and activation are arranged by Food Monster.</p></section>`;
  }
  function renderSecurity() {
    return title('ACCOUNT SECURITY','Keep your account yours.','Manage your password and signed-in sessions.') + `<div class="panel-grid"><section class="panel"><div class="panel-header"><div><h2>Change password</h2><p>Use a unique passphrase you haven’t used elsewhere.</p></div></div><form id="password-form">${formMessage}${passwordField('current_password','Current password','current-password','')}${passwordField('password','New password')}${passwordField('confirm_password','Confirm new password','new-password','')}<button class="button primary" type="submit">Update password</button><p class="form-note">Changing your password signs out your other sessions.</p></form></section><section class="panel"><div class="panel-header"><div><h2>Account access</h2><p>One central account for Food Monster.</p></div></div><dl class="info-list"><div><dt>Email</dt><dd>${escape(state.user.email)}</dd></div><div><dt>Email status</dt><dd>${state.user.email_verified ? badge('verified') : badge('verification_pending')}</dd></div><div><dt>Account type</dt><dd>${state.user.role==='admin' ? 'Administrator' : 'Customer'}</dd></div></dl><div class="subtle-divider"></div><h3>Sign out everywhere</h3><p class="form-note">End all signed-in sessions, including this one. You can log in again with your password.</p><button class="button secondary" type="button" data-action="logout-all">Sign out all sessions</button></section></div>`;
  }
  function renderOnboarding() {
    const progress = state.summary.onboarding || {step:'choose_plan'};
    const site = state.summary.installations.find(item=>item.id===progress.installation_id);
    const plan = state.summary.subscriptions.find(item=>item.id===progress.subscription_id);
    const refresh = recordButton('Refresh status','onboarding-refresh','');
    const manageSite = '<button type="button" class="button secondary" data-tab="restaurant">Manage my restaurant</button>';
    const plans = '<a class="button primary" href="plans.html">Choose a Cloud plan</a>';
    const states = {
      verify:['Verify your email','Open the verification link sent to your account email.'],
      choose_plan:['Choose a Cloud plan','Select a package and complete payment or submit a request for approval.'],
      payment:['Waiting for payment or approval','Your setup continues once Food Monster confirms the subscription.'],
      plan_action:['Review your Cloud plan','This reservation needs an active plan. Open My restaurant to relink it if another Cloud plan is available.'],
      reserve:['Reserve your subdomain','Save your site address before entering your Gelato owner details.'],
      domain_action:['Review your subdomain','Resolve the pending transfer or renew your registration to continue.'],
      owner:['Set up your Gelato owner account','Food Monster saves your owner profile before the hosting worker creates the site.'],
      manual_setup:['Hosting setup is pending','The platform team will complete the hosting setup.'],
      queued:['Your setup is queued','The hosting worker will pick up your saved setup.'],
      working:['Setting up Gelato','Food Monster is creating the site and applying your owner settings.'],
      awaiting_https:['Checking HTTPS','Your site link will appear here after the health check passes.'],
      retry_wait:['Setup will retry','Food Monster will retry from the last saved step.'],
      failed:['Setup needs attention','The platform team can review and retry your saved setup.'],
      cancelled:['Setup was cancelled','Open My restaurant to review the reservation.'],
      ready:['Your Gelato site is ready','Sign in with the owner credentials you chose.']
    };
    const [heading, description] = states[progress.step] || states.manual_setup;
    let controls = site ? manageSite : '';
    if (progress.step==='choose_plan') controls = plans;
    if (progress.step==='payment') controls = (plan ? subscriptionCard(plan) : '') + refresh;
    if (progress.step==='plan_action') controls += ' <button type="button" class="button secondary" data-tab="subscription">View subscriptions</button>';
    if (progress.step==='owner') controls = recordButton('Enter owner details','hosted-existing',site.id);
    if (['queued','working','awaiting_https','retry_wait','failed'].includes(progress.step)) controls += ' ' + refresh;
    if (progress.step==='ready' && /^[a-z0-9.-]+$/i.test(site.domain)) controls = `<a class="button primary" href="https://${escape(site.domain)}">Open Gelato</a> ${manageSite}`;
    if (progress.step==='reserve') controls = `<form id="onboarding-domain">${formMessage}
      ${field('business_name','Restaurant name',{value:state.summary.business.business_name,attrs:'minlength="2" maxlength="145"'})}
      ${field('subdomain','Subdomain',{attrs:'minlength="3" maxlength="40" pattern="[A-Za-z][A-Za-z0-9]*(?:-[A-Za-z0-9]+)*" autocomplete="off"'})}
      <p class="form-note">Your address will end in .${escape(state.summary.base_domain)}.</p><button class="button primary" type="submit">Reserve address</button></form>`;
    return title('FOOD MONSTER CLOUD','Set up your restaurant','Pick up where you left off whenever you sign in.') + verifyBanner() + `<section class="panel"><div class="panel-header"><div><h2>${heading}</h2><p>${description}</p>${site ? `<p>${escape(site.domain)}</p>` : ''}</div></div>${controls}</section>`;
  }
  function renderAccount() {
    const views = {overview:renderOverview,setup:renderOnboarding,restaurant:renderRestaurant,subscription:renderSubscription,security:renderSecurity};
    if (!views[state.tab]) state.tab = 'overview';
    shell(views[state.tab]());
    document.getElementById('profile-form')?.addEventListener('submit', event => {event.preventDefault(); submit(event.target,async () => {
      const values = formData(event.target); await api('account.update',{name:values.name,business_name:values.business_name});
      await reloadAccount(); toast('Business details saved.');
    });});
    document.getElementById('onboarding-domain')?.addEventListener('submit',event=>{
      event.preventDefault();
      submit(event.target,async()=>{
        await api('installations.register',{...formData(event.target),hosting_mode:'cloud'});
        await reloadAccount();
        toast('Subdomain reserved.');
      });
    });
    const installationForm = document.getElementById('installation-form');
    installationForm?.addEventListener('change',event=>{if(event.target.name==='hosting_mode'){state.hosting=event.target.value;renderAccount();}});
    installationForm?.addEventListener('input',event=>{if(event.target.name==='subdomain'){const el=document.getElementById('availability');if(el)el.hidden=true;}});
    installationForm?.addEventListener('submit',event=>{event.preventDefault();submit(event.target,async()=>{
      const values = formData(event.target);
      const action = state.summary.hosted_setup_ready && values.hosting_mode === 'cloud' ? 'installations.hosted_setup' : 'installations.register';
      const result=await api(action,values);await reloadAccount();toast(result.message || 'Your installation is registered.');
    });});
    document.getElementById('password-form')?.addEventListener('submit',event=>{event.preventDefault();submit(event.target,async()=>{
      const values=formData(event.target);if(values.password!==values.confirm_password)throw new Error('The new passwords do not match.');
      await api('auth.change_password',{current_password:values.current_password,password:values.password});
      await bootstrap(); event.target.reset(); showFeedback(event.target,'Password updated. Your other sessions have been signed out.','success');
    });});
  }
  async function reloadAccount() {
    state.summary=await api('account.summary');state.user=state.summary.user;renderAccount();
  }
  function renderPlans() {
    const plans=state.packages.filter(item=>item.hosting_modes?.includes(state.hosting) && item[`${state.interval}_price_cents`]!=null);
    app.innerHTML=`${header()}<main class="plans-main" id="portal-main"><div class="plans-heading"><div class="eyebrow"><span class="status-dot"></span>ROOM TO GROW</div><h1>Your restaurant.<br> <span>Your kind of setup.</span></h1><p>Choose the package that fits your business. Run Gelato on Food Monster Cloud or in your own server environment.</p></div><div class="plans-controls"><div class="segmented" role="group" aria-label="Hosting option"><button type="button" data-plan-hosting="cloud" class="${state.hosting==='cloud'?'active':''}" aria-pressed="${state.hosting==='cloud'}">${icon('cloud')}Food Monster Cloud</button><button type="button" data-plan-hosting="self_hosted" class="${state.hosting==='self_hosted'?'active':''}" aria-pressed="${state.hosting==='self_hosted'}">${icon('server')}Self-hosted</button></div><div class="segmented" role="group" aria-label="Billing interval"><button type="button" data-interval="monthly" class="${state.interval==='monthly'?'active':''}" aria-pressed="${state.interval==='monthly'}">Monthly</button><button type="button" data-interval="quarterly" class="${state.interval==='quarterly'?'active':''}" aria-pressed="${state.interval==='quarterly'}">Quarterly</button><button type="button" data-interval="annual" class="${state.interval==='annual'?'active':''}" aria-pressed="${state.interval==='annual'}">Annual</button></div></div>${!state.ready ? `<section class="panel">${empty('Packages will appear here.',state.bootError || unavailableText,'<a class="button secondary" href="signup.html">View account setup</a>')}</section>` : plans.length ? `<div class="plan-grid">${plans.map(item=>`<article class="plan-card"><span class="eyebrow">${hostingName(state.hosting)}</span><h2>${escape(item.name)}</h2><p>${escape(item.description)}</p><div class="package-price">${money(item[`${state.interval}_price_cents`],item.currency)}<span>/${intervalLabel[state.interval]}</span></div><div class="plan-allowance">${Number(item.max_installations)} ${Number(item.max_installations)===1?'restaurant installation':'restaurant installations'}</div><button class="button primary wide" type="button" data-action="request-package" data-id="${escape(item.id)}" ${checkoutPaused(item) ? 'disabled' : ''}>${checkoutPaused(item) ? 'Checkout paused' : paidCloudPlan(item) ? 'Subscribe' : 'Request package'} ${icon('arrow')}</button><ul class="package-features">${(item.features||[]).map(feature=>`<li>${icon('check')}<span>${escape(feature)}</span></li>`).join('')}</ul></article>`).join('')}</div>` : `<section class="panel">${empty(state.packages.length ? 'No packages for this combination yet.' : 'Packages are being prepared.',state.packages.length ? 'Try another hosting option or billing interval.' : 'Check back soon for available subscriptions.',state.user?.role==='admin' ? '<a class="button primary" href="admin.html">Manage packages '+icon('arrow')+'</a>' : '<a class="button secondary" href="signup.html">Create an account</a>')}</section>`}<p class="plan-footnote">${state.stripeReady && state.hosting==='cloud' ? 'Paid Cloud plans use Stripe. The plan includes a one-year subdomain registration within its installation limit.' : 'Requests are reviewed before activation. Submitting a request does not charge a card.'}</p><div class="panel-grid"><section class="panel"><div class="panel-header">${icon('cloud')}<h2>Food Monster Cloud</h2></div><p>Cloud includes a one-year subdomain registration and managed hosting. Your Gelato installation is configured for your business.</p></section><section class="panel"><div class="panel-header">${icon('server')}<h2>Self-hosted freedom</h2></div><p>Host Gelato in your own server environment. Manage your Food Monster subscription and registered installation here.</p></section></div></main><footer class="auth-footer"><span>© ${new Date().getFullYear()} Food Monster</span><a href="index.html" class="quiet-link">Back to website ↗</a></footer>`;
  }
  const hostedStage = {queued:'Reservation and release',host:'cPanel resources',seed:'Gelato and owner setup',verify:'HTTPS and Gelato health',ready:'Activated'};
  const hostedState = {queued:'Queued',working:'Working',awaiting_https:'Checking HTTPS',retry_wait:'Retry scheduled',failed:'Needs attention',cancelled:'Cancelled',ready:'Ready'};
  const hostedEvents = {queued:'Setup requested',stage:'Stage advanced',started:'Worker started',ready:'Activated',retry_wait:'Retry scheduled',awaiting_https:'Waiting for HTTPS',
    failed:'Needs attention',retry_requested:'Retry requested',cancel_requested:'Cancellation requested',release_requested:'Cleanup check requested',
    cancelled:'Cancelled',released:'Reservation released',command_failed:'Request needs attention'};
  const hostedActions = {retry:'Retry setup',cancel:'Cancel setup',release:'Verify cleanup and release'};
  const dateTime = value => value ? new Intl.DateTimeFormat(undefined,{dateStyle:'medium',timeStyle:'short'}).format(new Date(Number(value) * 1000)) : '—';

  const operationsViews = {setup:'Pending setup',hosting:'Hosting exceptions',billing:'Billing follow-up',domains:'Expiring domains',customers:'Customers'};
  async function loadOperations() {
    const request = ++state.opsLoad;
    const heading = title('PLATFORM ADMIN','Operations','Find customers and follow up on billing, setup, and domain renewals.');
    const filters = `<section class="panel"><div class="card-actions" role="group" aria-label="Operations views">${Object.entries(operationsViews).map(([view,label])=>`<button type="button" class="button ${view===state.opsView?'primary':'secondary'} small" aria-pressed="${view===state.opsView}" data-action="operations-view" data-id="${view}">${label}</button>`).join('')}</div><form id="operations-search"><div class="field"><label for="operations-email">Customer email</label><input id="operations-email" type="email" name="email" maxlength="254" value="${escape(state.opsEmail)}" placeholder="name@example.com"></div><button class="button secondary small">Filter</button> ${recordButton('Clear filter','operations-clear','')}</form></section>`;
    const draw = content => {
      shell(heading + filters + `<section class="panel">${content}</section>`,true);
      document.getElementById('operations-search').addEventListener('submit',event=>{
        event.preventDefault();
        state.opsEmail = new FormData(event.target).get('email').trim();
        state.opsPage = 1;
        loadOperations();
      });
    };
    draw('<p role="status">Loading operations…</p>');
    try {
      const data = await api('admin.operations',undefined,{view:state.opsView,page:state.opsPage,email:state.opsEmail});
      if (request !== state.opsLoad || state.tab !== 'operations') return;
      const cards = data.rows.map(row=>{
        const details = [['Email',row.email]];
        if (row.state) details.push(['Status',hostedState[row.state] || billingLabels[row.state] || row.state]);
        if (row.stage) details.push(['Hosting stage',hostedStage[row.stage] || row.stage]);
        if (row.reason) details.push(['Review reason',row.reason.replaceAll('_',' ')]);
        if (row.expires_at) details.push(['Expires',dateTime(row.expires_at)]);
        if (row.updated_at) details.push(['Last changed',dateTime(row.updated_at)]);
        return `<article class="installation-card"><h3>${escape(row.domain || row.package_name || row.customer_name)}</h3>${infoRows(details)}<div class="card-actions">${recordButton('Customer timeline','operations-timeline',row.customer_id)}${row.can_retry ? recordButton('Retry setup','hosted-command-retry',row.id) : ''}${row.can_recover ? recordButton('Recover checkout','operations-recover',row.id) : ''}</div></article>`;
      }).join('');
      draw(cards + (cards ? '' : '<p>No matching records.</p>') + `<div class="card-actions"><span>Page ${data.page}</span>${data.page>1?recordButton('Previous','operations-page',data.page-1):''}${data.has_more?recordButton('Next','operations-page',data.page+1):''}</div>`);
    } catch (error) {
      if (request !== state.opsLoad || state.tab !== 'operations') return;
      draw(`<p role="alert">${escape(error.message)}</p>${error.code==='upgrade_required'?'<a class="button primary" href="upgrade.php">Open database upgrade</a>':''}`);
    }
  }

  async function loadHostedJobs(jobPage = 1) {
    const request = ++state.hostedLoad;
    const heading = title('PLATFORM ADMIN','Hosting jobs','Follow provisioning and manage exceptions. The CLI worker processes requests from this queue.',
      button('Refresh','type="button" data-action="hosted-page" data-id="'+jobPage+'"'));
    shell(heading + '<section class="panel" role="status">Loading hosting jobs…</section>',true);
    try {
      const data = await api('admin.hosted_jobs',undefined,{page:jobPage});
      if (request !== state.hostedLoad || state.tab !== 'hosting') return;
      state.hostedPage = data.page;
      const cards = data.jobs.map(job => {
        const meta = [['Stage',hostedStage[job.stage] || job.stage],['Last changed',dateTime(job.updated_at)],['Installation ID',job.installation_id]];
        if (job.version) meta.push(['Gelato version',job.version]);
        if (job.due_at && job.state !== 'working') meta.push(['Next attempt',dateTime(job.due_at)]);
        if (job.command) meta.push(['Worker request',hostedActions[job.command] || job.command]);
        return `<article class="installation-card"><div class="installation-title"><div><h3>${escape(job.business_name)}</h3><p>${escape(job.domain)}</p></div><span class="badge">${escape(job.released_at ? 'Released' : hostedState[job.state] || job.state)}</span></div>
          ${job.detail ? `<p class="form-note">${escape(job.detail)}</p>` : ''}${infoRows(meta)}
          <div class="card-actions">${recordButton('View timeline','hosted-timeline',job.installation_id)}${job.actions.map(command=>recordButton(hostedActions[command],'hosted-command-'+command,job.installation_id)).join('')}</div></article>`;
      }).join('');
      const nav = `<div class="card-actions"><span class="form-note">Page ${escape(data.page)}</span>${data.page > 1 ? recordButton('Previous','hosted-page',data.page - 1) : ''}${data.has_more ? recordButton('Next','hosted-page',data.page + 1) : ''}</div>`;
      shell(heading + `<section class="panel">${cards || '<p>No hosting jobs on this page.</p>'}${nav}</section>`,true);
    } catch (error) {
      if (request !== state.hostedLoad || state.tab !== 'hosting') return;
      const upgrade = error.code === 'upgrade_required' ? '<a class="button primary" href="upgrade.php">Open database upgrade</a>' : '';
      shell(heading + `<section class="panel"><p role="alert">${escape(error.message)}</p>${upgrade}</section>`,true);
    }
  }

  async function loadReleaseAnalytics() {
    const request = ++state.analyticsLoad;
    const current = () => request === state.analyticsLoad && state.tab === 'analytics';
    const heading = title('PLATFORM ADMIN','Update analytics','See version adoption and deployment results by release.',
      button('Refresh','type="button" data-action="refresh-analytics"'));
    shell(heading + '<section class="panel" role="status">Loading update analytics…</section>',true);
    try {
      const data = await api('admin.release_analytics');
      if (!current()) return;
      const versions = data.versions.map(row=>[`${row.version || 'Unknown version'} · ${row.channel}`,row.total]);
      const cards = data.releases.map(release => `<article class="installation-card"><div class="installation-title">
        <div><h3>Gelato ${escape(release.version)}</h3><p>${escape(release.channel)} · ${dateTime(release.published_at)}</p></div>${badge(release.status)}</div>
        ${infoRows([['Currently installed',release.reported_installed],['Installations requesting an update',release.requested],
          ['Pending',release.pending],['Succeeded',release.succeeded],['Failed',release.failed],
          ['Verified rollbacks',release.rollbacks],['ZIP responses served',release.downloads]])}</article>`).join('');
      shell(heading + `<section class="panel"><div class="panel-header"><div><h2>Installed versions</h2><p>Latest recorded versions for active, connected installations.</p></div></div>
        ${versions.length ? infoRows(versions) : '<p>No connected installations have reported yet.</p>'}</section>
        <section class="panel"><div class="panel-header"><div><h2>Release results</h2><p>Each requesting installation counts once. An open attempt counts as pending. Otherwise, any successful attempt counts as succeeded.</p></div></div>
        <p class="form-note">ZIP counts cover manual download responses served since tracking began. Rollbacks count verified recovery attempts.</p>
        ${cards || '<p>No published releases yet.</p>'}</section>`,true);
    } catch (error) {
      if (!current()) return;
      const upgrade = error.code === 'upgrade_required' ? '<a class="button primary" href="upgrade.php">Open database upgrade</a>' : '';
      shell(heading + `<section class="panel"><p role="alert">${escape(error.message)}</p>${upgrade}</section>`,true);
    }
  }

  function renderAdmin() {
    const data=state.admin;
    if(state.tab==='cpanel') {
      renderCpanelAdmin(data.cpanel);
    } else if(state.tab==='packages') {
      shell(title('PLATFORM ADMIN','Packages, made for your customers.','Set your actual prices and publish the packages you’re ready to offer.',button('Create package','type="button" data-action="edit-package"','primary'))+`<section class="panel"><div class="panel-header"><div><h2>Package catalog</h2><p>Drafts stay private. Published packages appear on the packages page.</p></div><a href="plans.html" class="inline-link">View packages ↗</a></div>${data.packages.length ? `<div class="admin-package-grid">${data.packages.map(item=>`<article class="admin-package"><div class="installation-title"><h3>${escape(item.name)}</h3><span class="badge ${item.archived?'negative':item.published?'positive':''}">${item.archived?'Archived':item.published?'Published':'Draft'}</span></div><p>${escape(item.description)}</p><dl class="info-list"><div><dt>Monthly</dt><dd>${money(item.monthly_price_cents,item.currency)}</dd></div><div><dt>Quarterly</dt><dd>${money(item.quarterly_price_cents ?? null,item.currency)}</dd></div><div><dt>Annual</dt><dd>${money(item.annual_price_cents,item.currency)}</dd></div><div><dt>Installations</dt><dd>${Number(item.max_installations)}</dd></div><div><dt>Hosting</dt><dd>${item.hosting_modes.map(hostingName).join(', ')}</dd></div></dl>${!item.archived ? `<div class="card-actions">${button('Edit',`type="button" data-action="edit-package" data-id="${escape(item.id)}"`,'secondary small')}${button('Archive',`type="button" data-action="archive-package" data-id="${escape(item.id)}"`,'danger small')}</div>` : ''}</article>`).join('')}</div>` : empty('Build your first package.','Add your price, hosting choices, and features. Publish when it’s ready.',button('Create package','type="button" data-action="edit-package"','primary'))}</section>${configurationPanel(data.configuration)}`,true);
    } else if(state.tab==='subscriptions') {
      shell(title('PLATFORM ADMIN','Subscription requests.','Review requests, record approval, and manage access periods.')+`<div class="status-banner">${icon('card')}<div><strong>Subscription billing</strong><p>Stripe manages paid Cloud subscriptions when enabled. Manual requests use administrator approval.</p></div></div><section class="panel"><div class="panel-header"><h2>All subscriptions</h2><span class="badge">${data.subscriptions.length}</span></div>${data.subscriptions.length ? data.subscriptions.map(item=>subscriptionCard(item,true)).join('') : empty('No requests yet.','Customer package requests will appear here.','',true)}</section>`,true);
    } else if(['subdomains','licenses','releases','updates'].includes(state.tab)) {
      shell(renderLicensingAdmin(data),true);
    } else if(state.tab==='installations') {
      shell(title('PLATFORM ADMIN','Every restaurant, in one place.','Verify domains and record the real progress of each Gelato installation.')+`<div class="status-banner">${icon('server')}<div><strong>Cloud provisioning and manual installations</strong><p>Managed sites follow the hosted setup status. Complete DNS, TLS, and setup checks before marking a manual installation active.</p></div></div><section class="panel"><div class="panel-header"><h2>Installation registry</h2><span class="badge">${data.installations.length}</span></div>${data.installations.length ? data.installations.map(item=>installationCard(item,true)).join('') : empty('Your first restaurant is up next.','Registered cloud and self-hosted installations will appear here.','',true)}</section>`,true);
    } else {
      shell(title('PLATFORM ADMIN','Meet your customers.','Account and verification details for your Food Monster customers.')+`<section class="panel"><div class="panel-header"><h2>Customer accounts</h2><span class="badge">${data.customers.length}</span></div>${data.customers.length ? data.customers.map(item=>`<div class="record-row"><div><strong>${escape(item.name)}</strong><p>${escape(item.email)}</p><small>${escape(item.business_name || '')} · Joined ${date(item.created_at)}</small></div><div class="admin-customer-meta">${item.role==='admin'?'<span class="badge">Administrator</span>':''}${item.email_verified ? badge('verified') : badge('verification_pending')}</div></div>`).join('') : empty('No customer accounts yet.','New registrations will appear here.','',true)}</section>`,true);
    }
  }
  function renderCpanelAdmin(config) {
    const heading = title('PLATFORM ADMIN','cPanel connection','Connect the hosting account that will provision Gelato subdomains.');
    if (!config) {
      shell(heading + '<section class="panel"><p>Run the hosted setup database upgrade first.</p><a class="button primary" href="upgrade.php">Open database upgrade</a></section>',true);
      return;
    }
    const status = config.tested_at ? 'Verified ' + date(config.tested_at) : config.token_configured ? 'Saved, awaiting test' : 'Token required';
    shell(heading + `<section class="panel"><div class="panel-header"><h2>HostGator account</h2><span class="badge">${escape(status)}</span></div>
      <form id="cpanel-form">${formMessage}
        ${field('hostname','cPanel server hostname',{value:config.hostname || '',attrs:'placeholder="gator1234.hostgator.com" autocomplete="off" maxlength="253"'})}
        ${field('username','cPanel username',{value:config.username || '',attrs:'autocomplete="off" maxlength="16"'})}
        ${field('database_prefix','MySQL name prefix',{value:config.database_prefix || '',required:false,attrs:'maxlength="16"',help:'Enter the prefix shown in cPanel, without the trailing underscore. Leave blank if your account has prefixing disabled.'})}
        ${field('token','cPanel API token',{type:'password',required:!config.token_configured,autocomplete:'new-password',attrs:'maxlength="256"',help:config.token_configured?'Leave blank to keep the saved token for this server and account.':'The token is encrypted after saving.'})}
        <div class="card-actions">${button('Save connection','type="submit"','primary')}${config.token_configured?button('Test connection','type="button" data-action="cpanel-test"'):''}</div>
      </form></section>${config.token_configured ? `<section class="panel"><div class="panel-header"><div><h2>Hosting preflight</h2><p>Review directory layout, account limits, PHP, AutoSSL and the signed release.</p></div>${button('Run preflight','type="button" data-action="cpanel-preflight"')}</div><div id="cpanel-preflight" aria-live="polite"></div></section>` : ''}`,true);
    document.getElementById('cpanel-form').addEventListener('submit',event=>{event.preventDefault();submit(event.target,async()=>{
      await api('admin.cpanel_save',formData(event.target)); await reloadAdmin(); toast('cPanel connection saved.');
    });});
  }
  function configurationPanel(config={}) {
    return `<section class="panel"><div class="panel-header"><div><h2>Platform services</h2><p>What is connected to this installation.</p></div></div><div class="configuration-list"><div class="configuration-item"><span>Cloud domain</span><strong>${escape(config.base_domain || 'Not configured')}</strong></div><div class="configuration-item"><span>Subscriptions</span><span class="badge">${config.payments_configured ? 'Stripe and manual' : 'Manual review'}</span></div><div class="configuration-item"><span>Payment processing</span><span class="badge">${config.payments_configured ? 'Connected' : 'Not connected'}</span></div><div class="configuration-item"><span>Automatic hosting</span><span class="badge">${config.hosting_configured ? 'Connected' : 'Not connected'}</span></div><div class="configuration-item"><span>Gelato distribution & updates</span><span class="badge">${config.gelato_distribution_configured ? 'Connected' : 'Not connected'}</span></div><div class="configuration-item"><span>Domain verification</span><span class="badge ${config.dns_lookup_available ? 'positive' : ''}">${config.dns_lookup_available ? 'DNS TXT available' : 'DNS lookup unavailable'}</span></div></div></section>`;
  }
  async function reloadAdmin() {
    if (state.tab === 'operations') return loadOperations();
    if (state.tab === 'analytics') {
      await loadReleaseAnalytics();
      return;
    }
    if (state.tab === 'hosting') {
      await loadHostedJobs(state.hostedPage);
      return;
    }
    state.admin=await api('admin.overview');
    if (!['operations','hosting','analytics'].includes(state.tab)) renderAdmin();
  }
  function openDialog(titleText,description,fields,onSubmit,submitLabel='Save changes',danger=false) {
    const previous=document.activeElement;
    dialog.innerHTML=`<div class="dialog-inner"><div class="dialog-heading"><h2 id="dialog-title">${escape(titleText)}</h2><button type="button" class="dialog-close" data-close-dialog aria-label="Close dialog">${icon('close')}</button></div><p>${escape(description)}</p><form>${formMessage}${fields}<div class="dialog-actions"><button type="button" class="button secondary" data-close-dialog>Cancel</button><button type="submit" class="button ${danger?'danger':'primary'}">${escape(submitLabel)}</button></div></form></div>`;
    dialog.setAttribute('aria-labelledby','dialog-title');
    dialog.showModal();
    dialog.addEventListener('close',()=>{if(previous?.isConnected)previous.focus();},{once:true});
    dialog.querySelector('form').addEventListener('submit',event=>{event.preventDefault();submit(event.target,async()=>{
      await onSubmit(formData(event.target),event.target);dialog.close();
    });});
  }
  function editPackage(id) {
    const item=state.admin.packages.find(entry=>entry.id===id) || {name:'',description:'',monthly_price_cents:null,quarterly_price_cents:null,annual_price_cents:null,currency:'USD',max_installations:1,hosting_modes:['cloud','self_hosted'],features:[],published:false};
    openDialog(id?'Edit package':'Create a package','Enter the actual offer you want customers to request.',`${field('package_name','Package name',{name:'name',value:item.name,attrs:'maxlength="120"'})}<div class="field"><label for="description">Description</label><textarea id="description" name="description" maxlength="1000" required>${escape(item.description)}</textarea></div><div class="field-row">${field('monthly_price','Monthly price',{type:'number',value:item.monthly_price_cents===null?'':(item.monthly_price_cents/100).toFixed(2),required:false,attrs:'min="0" max="1000000" step="0.01"',help:'Leave blank to disable monthly.'})}${field('quarterly_price','Quarterly price',{type:'number',value:item.quarterly_price_cents==null?'':(item.quarterly_price_cents/100).toFixed(2),required:false,attrs:'min="0" max="1000000" step="0.01"',help:'Total for three months.'})}${field('annual_price','Annual price',{type:'number',value:item.annual_price_cents===null?'':(item.annual_price_cents/100).toFixed(2),required:false,attrs:'min="0" max="1000000" step="0.01"',help:'Leave blank to disable annual.'})}</div><div class="field-row"><div class="field"><label for="currency">Currency</label><select id="currency" name="currency">${['USD','EUR','GBP','CAD','AUD','NZD','CHF','SEK','NOK','DKK','SGD','HKD','ZAR'].map(code=>`<option value="${code}" ${item.currency===code?'selected':''}>${code}</option>`).join('')}</select><small>Supported currencies use two decimal places.</small></div>${field('max_installations','Installation limit',{type:'number',value:item.max_installations,attrs:'min="1" max="1000" step="1"'})}</div><span class="block-label">Supported hosting</span><div class="checkbox-row"><label class="check-label"><input type="checkbox" name="cloud" ${item.hosting_modes.includes('cloud')?'checked':''}>Food Monster Cloud</label><label class="check-label"><input type="checkbox" name="self_hosted" ${item.hosting_modes.includes('self_hosted')?'checked':''}>Self-hosted</label></div><div class="field"><label for="features">Included features</label><textarea id="features" name="features" maxlength="5000" placeholder="One feature per line">${escape(item.features.join('\n'))}</textarea></div><label class="check-label"><input type="checkbox" name="published" ${item.published?'checked':''}>Publish this package</label><p class="form-note">A price of 0 is an explicitly free offer. Published packages need at least one enabled billing interval.</p>`,async values=>{
      const cents=value=>value.trim()===''?null:Math.round(Number(value)*100);
      const payload={name:values.name,description:values.description,monthly_price_cents:cents(values.monthly_price),quarterly_price_cents:cents(values.quarterly_price),annual_price_cents:cents(values.annual_price),currency:values.currency.toUpperCase(),max_installations:Number(values.max_installations),hosting_modes:['cloud','self_hosted'].filter(mode=>values[mode]==='on'),features:values.features.split('\n').map(value=>value.trim()).filter(Boolean),published:values.published==='on'};
      if(id)payload.id=id;await api('admin.package_save',payload);await reloadAdmin();toast('Package saved.');
    },'Save package');
  }
  async function openStripe(action, selection) {
    const result = await api(action,selection);
    if (result.url) location.assign(result.url);
    else {
      if (page === 'account') await reloadAccount();
      toast(result.message);
    }
  }
  async function handleSubdomainAction(action,id) {
    const admin = page === 'admin';
    if (['subdomain-renew','subdomain-relink','subdomain-offer'].includes(action)) {
      const item = (admin ? state.admin : state.summary).installations.find(site=>site.id===id);
      if (action === 'subdomain-renew') {
        openDialog('Renew for one year',item.domain,'<p class="form-note">Renewal uses an eligible Cloud plan. It extends your subdomain registration by one year.</p>',async()=>{
          await api('subdomains.renew',{installation_id:id,expected_expires_at:item.subdomain_registration.expires_at});
          await reloadAccount();
          toast('Subdomain renewed for one year.');
        },'Renew registration');
      } else if (action === 'subdomain-relink') {
        openDialog('Link an active Cloud plan',item.domain,'<p class="form-note">Use another active Cloud plan on your account with an available installation.</p>',async()=>{
          await api('subdomains.relink',{installation_id:id});
          await reloadAccount();
          toast('Cloud plan linked.');
        },'Link plan');
      } else {
        openDialog('Transfer subdomain',item.domain,field('recipient_email','Recipient’s Food Monster email',{type:'email',attrs:'maxlength="254"',
          help:'The receiving account must verify its email and accept the transfer. The registration keeps its current expiry.'}),async values=>{
          await api(admin?'admin.subdomain_offer':'subdomains.offer',{installation_id:id,recipient_email:values.recipient_email});
          await (admin ? reloadAdmin() : reloadAccount());
          toast('Transfer offer created.');
        },'Offer transfer');
      }
      return;
    }
    const decision = action.slice('subdomain-'.length);
    const label = {accept:'Accept transfer',decline:'Decline transfer',cancel:'Cancel transfer'}[decision];
    const description = decision === 'accept' ? 'Verify your email to accept. The registration will move to an active Cloud plan on your account with an available installation.' : 'The registration will stay with its current owner.';
    openDialog(label,description,'',async()=>{
      await api('subdomains.respond',{transfer_id:id,decision});
      await reloadAccount();
      toast('Transfer updated.');
    },label);
  }
  async function handleAction(action,id,target) {
    if (action === 'stripe-recover') {
      const plan = state.summary.subscriptions.find(item=>item.id===id);
      openDialog('Recover Stripe checkout',plan.package_name,'<p class="form-note">We’ll check this order with Stripe. An unpaid checkout will be closed so you can choose your plan again. A completed payment will be reviewed for access.</p>',
        async()=>await openStripe('stripe.recover',{subscription_id:id}),'Check with Stripe');
      return;
    }
    if (action === 'stripe-portal' || action === 'stripe-resume') {
      const plan = state.summary.subscriptions.find(item=>item.id===id);
      if (action === 'stripe-portal') await openStripe('stripe.portal',{subscription_id:id});
      else await openStripe('stripe.checkout',{package_id:plan.package_id,billing_interval:plan.billing_interval,hosting_mode:plan.hosting_mode});
      return;
    }
    if (['subdomain-renew','subdomain-relink','subdomain-offer','subdomain-accept','subdomain-decline','subdomain-cancel'].includes(action)) {
      await handleSubdomainAction(action,id);
      return;
    }
    if (['operations-view','operations-page','operations-clear'].includes(action)) {
      if (action === 'operations-view') state.opsView = id;
      if (action === 'operations-clear') state.opsEmail = '';
      state.opsPage = action === 'operations-page' ? Number(id) : 1;
      await loadOperations();
      return;
    }
    if (action === 'operations-timeline') {
      const data = await api('admin.customer_timeline',undefined,{customer_id:id});
      if (state.tab !== 'operations') return;
      const events = data.events.map(event=>[dateTime(event.at),[event.title,event.domain].filter(Boolean).join(' · ')]);
      const subscriptions = data.subscriptions.map(row=>[row.package_name,[row.status,billingLabels[row.billing_state],row.billing_review].filter(Boolean).join(' · ')]);
      const sites = data.sites.map(row=>[row.domain,[row.status,hostedState[row.setup_state],row.expires_at ? dateTime(row.expires_at) : ''].filter(Boolean).join(' · ')]);
      openDialog(data.customer.name,data.customer.email,`<h3>Subscriptions</h3>${subscriptions.length?infoRows(subscriptions):'<p>No subscriptions.</p>'}<h3>Sites</h3>${sites.length?infoRows(sites):'<p>No sites.</p>'}<h3>Recent events</h3>${infoRows(events)}`,async()=>{},'Close');
      return;
    }
    if (action === 'operations-recover') {
      openDialog('Recover Stripe checkout','This checks Stripe for a verified payment and expires an open unpaid checkout. The customer can start a new checkout after recovery.','',async()=>{
        await api('admin.stripe_recover',{subscription_id:id});
        await reloadAdmin();
        toast('Stripe checkout checked.');
      },'Check checkout');
      return;
    }
    if (action === 'refresh-analytics') {
      await loadReleaseAnalytics();
      return;
    }
    if (action.startsWith('license-') || action.startsWith('release-') && action !== 'release-domain' || action === 'rename-domain' || action === 'subdomain-add') {
      await handleLicensingAction(action,id); return;
    }
    if (action === 'hosted-page') {
      await loadHostedJobs(Number(id));
      return;
    }
    if (action === 'hosted-timeline') {
      const {events} = await api('admin.hosted_timeline',undefined,{installation_id:id});
      if (state.tab !== 'hosting') return;
      const rows = events.map(event=>[dateTime(event.created_at),[hostedEvents[event.event] || event.event,hostedStage[event.stage] || event.stage,event.detail].filter(Boolean).join(' · ')]);
      openDialog('Hosting timeline',id,rows.length ? infoRows(rows) : '<p>No events recorded yet.</p>',async()=>{},'Close');
      return;
    }
    if (action.startsWith('hosted-command-')) {
      const command = action.slice('hosted-command-'.length);
      const messages = {
        retry:'The worker will resume from the saved stage.',
        cancel:'The worker clears the staged owner profile. If hosting resources exist, it keeps the reservation for cleanup.',
        release:'The worker releases the hostname after confirming the domain, directory, database, and database user are gone.'
      };
      if (!messages[command]) return;
      openDialog(hostedActions[command],messages[command],'',async()=>{
        await api('admin.hosted_command',{installation_id:id,command});
        await reloadAdmin();
        toast('Request queued for the hosting worker.');
      },hostedActions[command],command === 'cancel');
      return;
    }
    if (action === 'cpanel-preflight') {
      const output = document.getElementById('cpanel-preflight');
      target.disabled = true;
      output.textContent = 'Checking the saved cPanel connection and hosting configuration…';
      try {
        const data = await api('admin.cpanel_preflight',{});
        const labels = {pass:'Passed',fail:'Action required',review:'Review'};
        output.innerHTML = `<p class="form-note">Checked ${escape(dateTime(data.checked_at))}</p>${infoRows(data.checks.map(check=>[check.label,(labels[check.status] || check.status)+' · '+check.detail]))}`;
      } catch (error) {
        output.textContent = error.message;
      } finally {
        target.disabled = false;
      }
      return;
    }
    if (action === 'cpanel-test') {
      target.disabled = true;
      try { await api('admin.cpanel_test',{}); await reloadAdmin(); toast('Connection verified. The cPanel account owns the parent domain.'); }
      finally { target.disabled = false; }
      return;
    }
    if (action === 'onboarding-refresh') { await reloadAccount(); return; }
    if (action === 'hosted-existing') {
      const item = state.summary.installations.find(entry=>entry.id===id);
      openDialog('Set up your Gelato site',item.domain,
        field('business_name','Restaurant name',{value:item.business_name,attrs:'maxlength="145"'})+hostedFields(),async values=>{
          const result = await api('installations.hosted_setup',{...values,installation_id:id});
          await reloadAccount(); toast(result.message);
        },'Submit site setup');
      return;
    }
    if(action==='logout'){await api('auth.logout',{});location.assign('login.html');}
    else if(action==='resend'){target.disabled=true;try{const result=await api('auth.resend',{});toast(result.message || 'Check your inbox for your verification link.');}finally{target.disabled=false;}}
    else if(action==='logout-all')openDialog('Sign out all sessions?','This includes your current session. You can sign back in with your password.','',async()=>{await api('auth.logout_all',{});location.assign('login.html');},'Sign out everywhere');
    else if(action==='check-domain') {
      const input=document.getElementById('subdomain');if(!input.reportValidity())return;
      const el=document.getElementById('availability');target.disabled=true;
      try{const result=await api('domains.check',{subdomain:input.value});el.textContent=result.message || (result.available?'Available to reserve.':'This name is unavailable.');el.className=`availability${result.available?'':' error'}`;el.hidden=false;}
      finally{target.disabled=false;}
    } else if(action==='verify-domain') {
      target.disabled=true;try{const result=await api('installations.verify',{installation_id:id});if(page==='admin')await reloadAdmin();else await reloadAccount();toast(result.message || (result.verified?'Domain verified.':'The TXT record is not visible yet. Please try again after DNS updates.'));}finally{target.disabled=false;}
    } else if(action==='release-domain')openDialog('Release this registration?','The reserved name will become available again. You can only release an installation before deployment starts.','',async()=>{await api('installations.release',{installation_id:id});await reloadAccount();toast('Registration released.');},'Release registration',true);
    else if(action==='request-package') {
      if(!state.user){location.assign('signup.html');return;}
      if(!verificationSatisfied()){openDialog('Verify your email first.','Open the verification link from your email, then return to choose your package.','',async()=>{await api('auth.resend',{});toast('Verification email requested.');},'Resend verification');return;}
      const item=state.packages.find(entry=>entry.id===id);
      if(checkoutPaused(item)){toast('New Stripe checkout is temporarily paused.');return;}
      const stripe = paidCloudPlan(item);
      const selection = {package_id:id,billing_interval:state.interval,hosting_mode:state.hosting};
      const details = stripe ? '<p class="form-note">Stripe will show the recurring total before you pay. Once payment is confirmed, choose your one-year subdomain and complete Gelato owner setup.</p>'
        : '<p class="form-note">Food Monster will review your request and arrange activation.</p>';
      openDialog((stripe ? 'Subscribe to ' : 'Request ')+item.name,
        `${hostingName(state.hosting)} · ${money(item[`${state.interval}_price_cents`],item.currency)} / ${intervalLabel[state.interval]}`,details,async()=>{
          if (stripe) await openStripe('stripe.checkout',selection);
          else { await api('subscription.request',selection);location.assign('account.html?section=subscription'); }
        },stripe?'Continue to Stripe':'Submit request');
    } else if(action==='edit-package')editPackage(id);
    else if(action==='archive-package')openDialog('Archive this package?','It will be removed from new requests. Existing subscriptions keep their recorded package terms.','',async()=>{await api('admin.package_archive',{package_id:id});await reloadAdmin();toast('Package archived.');},'Archive package',true);
    else if(action==='activate-subscription') {
      const item=state.admin.subscriptions.find(entry=>entry.id===id);
      openDialog('Activate subscription',`${item.customer_name} · ${item.package_name}`,`${field('expires_at','Access expires on',{type:'date',attrs:`min="${new Date(Date.now()+86400000).toISOString().slice(0,10)}"`,help:'Access runs through the end of this date in UTC.'})}${field('reference','Approval or payment reference',{attrs:'minlength="3" maxlength="200"',help:'Record why access is being granted. This does not collect a payment.'})}`,async values=>{await api('admin.subscription_activate',{subscription_id:id,expires_at:Math.floor(Date.parse(values.expires_at+'T23:59:59Z')/1000),reference:values.reference});await reloadAdmin();toast('Subscription activated.');},'Activate subscription');
    } else if(action==='suspend-subscription')openDialog('Suspend subscription','This blocks new registrations and activation under this subscription. A running customer server requires separate operational action.',`${field('reason','Reason',{attrs:'minlength="3" maxlength="1000"'})}`,async values=>{await api('admin.subscription_suspend',{subscription_id:id,reason:values.reason});await reloadAdmin();toast('Subscription suspended.');},'Suspend subscription',true);
    else if(action==='installation-status') {
      const item=state.admin.installations.find(entry=>entry.id===id);
      const transitions={reserved:['verification_pending','provisioning','suspended','failed'],verification_pending:['verification_pending','provisioning','suspended','failed'],verified:['provisioning','active','suspended','failed'],provisioning:['provisioning','active','suspended','failed'],active:['active','suspended','failed'],suspended:['provisioning','active','suspended','failed'],failed:['verification_pending','provisioning','active','suspended','failed']};
      const statuses=transitions[item.status] || [];
      openDialog('Update installation status',item.domain,`<div class="field"><label for="status">Status</label><select id="status" name="status">${statuses.map(status=>`<option value="${status}" ${item.status===status?'selected':''}>${statusNames[status]}</option>`).join('')}</select></div><div class="field"><label for="note">Operational note</label><textarea id="note" name="note" required minlength="10" maxlength="2000" placeholder="What was completed or changed?"></textarea></div><label class="check-label"><input type="checkbox" name="deployment_confirmed">I confirmed that the Gelato installation opens at this domain.</label><p class="form-note">Confirmation is required when marking active. The domain must also pass DNS verification and have a current subscription. This form records your work; it does not deploy the software.</p>`,async values=>{if(values.status==='active'&&values.deployment_confirmed!=='on')throw new Error('Confirm the working Gelato installation before marking it active.');await api('admin.installation_status',{installation_id:id,status:values.status,note:values.note});await reloadAdmin();toast('Installation status updated.');},'Update status');
    }
  }
  document.addEventListener('click',async event=>{
    const toggle=event.target.closest('[data-password]');
    if(toggle){const input=document.getElementById(toggle.dataset.password);const visible=input.type==='password';input.type=visible?'text':'password';toggle.setAttribute('aria-pressed',String(visible));toggle.setAttribute('aria-label',(visible?'Hide ':'Show ')+input.labels[0].textContent.toLowerCase());return;}
    if(event.target.closest('[data-close-dialog]')){dialog.close();return;}
    const tab=event.target.closest('[data-tab]');
    if(tab){state.tab=tab.dataset.tab;history.replaceState(null,'',location.pathname+'?section='+encodeURIComponent(state.tab));if(page==='admin'){if(['operations','hosting','analytics'].includes(state.tab)||!state.admin){try{await reloadAdmin();}catch(error){toast(error.message);}}else renderAdmin();}else renderAccount();return;}
    const interval=event.target.closest('[data-interval]');if(interval){state.interval=interval.dataset.interval;renderPlans();return;}
    const hosting=event.target.closest('[data-plan-hosting]');if(hosting){state.hosting=hosting.dataset.planHosting;renderPlans();return;}
    const copy=event.target.closest('[data-copy]');if(copy){try{await navigator.clipboard.writeText(copy.dataset.copy);toast('Copied to clipboard.');}catch{toast('Copy is unavailable. Select and copy the record text.');}return;}
    const action=event.target.closest('[data-action]');if(action){try{await handleAction(action.dataset.action,action.dataset.id,action);}catch(error){toast(error.message);}}
  });
  async function start() {
    try{await bootstrap();}catch(error){state.bootError=error.message;}
    if(['signup','login','forgot','reset','verify'].includes(page)){renderAuth();return;}
    if(page==='plans'){
      if(state.ready){try{const data=await api('packages.list');state.packages=data.packages || [];state.stripeReady=data.stripe_ready === true;state.stripeCheckoutEnabled=data.stripe_checkout_enabled !== false;}catch(error){state.ready=false;state.bootError=error.message;}}
      renderPlans();return;
    }
    if(!state.ready){renderUnavailable();return;}
    if(!state.user){location.replace(page==='admin'?'login.html?next=admin':'login.html');return;}
    state.tab=new URLSearchParams(location.search).get('section') || (page==='admin'?'operations':'overview');
    try{
      if(page==='admin'){
        if(state.user.role!=='admin'){app.innerHTML=`${header()}<main id="portal-main" class="service-unavailable"><h1>Administrator access required.</h1><p>This area is available to Food Monster platform administrators.</p><a class="button primary" href="account.html">Go to my account</a></main>`;return;}
        if(!['operations','packages','subscriptions','installations','subdomains','licenses','releases','updates','analytics','cpanel','hosting','customers'].includes(state.tab))state.tab='operations';
        await reloadAdmin();
      }else await reloadAccount();
    }catch(error){state.bootError=error.message;renderUnavailable();}
  }
  start();
})();
