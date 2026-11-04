(() => {
  'use strict';

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const icon = name => `<svg class="icon" aria-hidden="true"><use href="#i-${name}"/></svg>`;
  const monster = '<img src="assets/monster.svg" alt="Food Monster" width="26" height="26">';
  const state = { scenario: 'prep', completed: new Set(), restockAdded: false, draftOpen: false };
  const prepTasks = [
    { id: 'dough', name: 'Portion the dough', station: 'Pizza station', quantity: '24 balls' },
    { id: 'mushrooms', name: 'Slice mushrooms', station: 'Cold prep', quantity: '2 kg' },
    { id: 'dressing', name: 'Make house dressing', station: 'Cold prep', quantity: '1 batch' }
  ];
  const scenarios = {
    prep: { question: 'What’s left to prep before dinner?', title: 'Three things. Then you’re ready for service.', subtitle: 'Here’s the dinner prep list for our sample kitchen. Try checking an item off.' },
    inventory: { question: 'What are we running low on?', title: 'Two ingredients could use a top-up.', subtitle: 'Fresh mozzarella and Roma tomatoes are below the sample kitchen’s target levels.' },
    team: { question: 'Who’s on the line tonight?', title: 'Meet tonight’s sample line-up.', subtitle: 'Three people, one good service. Here’s who’s where.' }
  };

  let toastTimer;
  function toast(message) {
    const element = $('#toast');
    clearTimeout(toastTimer);
    element.textContent = message;
    element.classList.add('show');
    toastTimer = setTimeout(() => element.classList.remove('show'), 3200);
  }

  function prepResult() {
    const completedCount = state.completed.size;
    return `<div class="demo-result" aria-label="Sample dinner prep list">${prepTasks.map(task => {
      const checked = state.completed.has(task.id);
      return `<button class="demo-task" type="button" data-task="${task.id}" aria-pressed="${checked}"><span class="check-box">${icon('check')}</span><span><span class="demo-task-title">${task.name}</span><span class="demo-task-meta">${task.station}</span></span><span class="demo-task-quantity">${task.quantity}</span></button>`;
    }).join('')}</div><div class="demo-progress-row"><span id="prep-progress">${completedCount === 3 ? 'All set. Nice work, kitchen.' : `${completedCount} of 3 sample tasks complete`}</span><span class="demo-progress-bar" aria-hidden="true"><span style="width:${completedCount / 3 * 100}%"></span></span></div>`;
  }

  function inventoryResult() {
    if (state.draftOpen) {
      return `<div class="demo-result"><div class="draft-heading"><span>SAMPLE RESTOCK DRAFT</span><span>DRAFT</span></div><div class="draft-detail"><strong>Fresh mozzarella</strong><span>4 tubs</span></div><div class="draft-detail"><strong>Roma tomatoes</strong><span>2 cases</span></div></div><p class="demo-answer-subtitle">Quantities bring stock back to sample targets. A manager would review supplier, price, and quantity before any order.</p><button class="demo-action secondary" type="button" data-demo-action="back-inventory">${icon('arrow')} Back to sample inventory</button>`;
    }
    return `<div class="demo-result"><div class="demo-stock-row"><span class="ingredient-icon">M</span><div><strong>Fresh mozzarella</strong><small>2 tubs on hand · Target: 6 tubs</small></div><span class="stock-low">Low stock</span></div><div class="demo-stock-row"><span class="ingredient-icon">T</span><div><strong>Roma tomatoes</strong><small>1 case on hand · Target: 3 cases</small></div><span class="stock-low">Low stock</span></div></div><button class="demo-action" type="button" data-demo-action="${state.restockAdded ? 'open-draft' : 'add-restock'}">${icon(state.restockAdded ? 'arrow' : 'plus')}${state.restockAdded ? 'View sample restock draft' : 'Add both to Need More'}</button>${state.restockAdded ? '<p class="demo-answer-subtitle">Both items added to the sample Need More list.</p>' : ''}`;
  }

  function teamResult() {
    const people = [
      { initials: 'AL', name: 'Alex', role: 'Pizza station', time: '4–10 PM', color: 'green' },
      { initials: 'JM', name: 'Jordan', role: 'Sauté station', time: '4–10 PM', color: 'peach' },
      { initials: 'SK', name: 'Sam', role: 'Prep & support', time: '2–8 PM', color: 'purple' }
    ];
    return `<div class="demo-result">${people.map(person => `<div class="demo-team-row"><span class="team-avatar avatar-${person.color}">${person.initials}</span><div><strong>${person.name}</strong><small>${person.role}</small></div><span class="team-time">${person.time}</span></div>`).join('')}</div><p class="demo-answer-subtitle">Sample team and shift times for this demonstration.</p>`;
  }

  function renderDemo({ animate = false } = {}) {
    const scenario = scenarios[state.scenario];
    const container = $('#demo-conversation');
    let title = scenario.title;
    if (state.scenario === 'prep') {
      const remaining = prepTasks.length - state.completed.size;
      title = remaining === 0 ? 'Dinner prep? All taken care of.' : `${['', 'One thing', 'Two things', 'Three things'][remaining]}. Then you’re ready for service.`;
    }
    if (state.scenario === 'inventory' && state.draftOpen) title = 'A little planning. A well-stocked kitchen.';
    const result = state.scenario === 'prep' ? prepResult() : state.scenario === 'inventory' ? inventoryResult() : teamResult();
    container.innerHTML = `<div class="demo-question">${scenario.question}</div><div class="demo-answer">${monster}<div class="demo-answer-content"><h3 class="demo-answer-title">${title}</h3><p class="demo-answer-subtitle">${scenario.subtitle}</p>${result}</div></div>`;
    if (animate && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      container.classList.remove('changing');
      void container.offsetWidth;
      container.classList.add('changing');
    }
  }

  function selectScenario(name, { focusTab = false, scroll = false } = {}) {
    if (!Object.hasOwn(scenarios, name)) return;
    state.scenario = name;
    $$('.demo-prompt').forEach(button => {
      const selected = button.dataset.scenario === name;
      button.classList.toggle('active', selected);
      button.setAttribute('aria-selected', String(selected));
      button.tabIndex = selected ? 0 : -1;
      if (selected && focusTab) button.focus();
    });
    $('#demo-panel').setAttribute('aria-labelledby', `tab-${name}`);
    renderDemo({ animate: true });
    if (scroll) {
      $(`#tab-${name}`).focus({ preventScroll: true });
      $('#demo').scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
    }
  }

  $$('.demo-prompt').forEach((button, index, buttons) => {
    button.addEventListener('click', () => selectScenario(button.dataset.scenario));
    button.addEventListener('keydown', event => {
      let next;
      if (event.key === 'ArrowDown' || event.key === 'ArrowRight') next = (index + 1) % buttons.length;
      if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') next = (index - 1 + buttons.length) % buttons.length;
      if (event.key === 'Home') next = 0;
      if (event.key === 'End') next = buttons.length - 1;
      if (next !== undefined) {
        event.preventDefault();
        selectScenario(buttons[next].dataset.scenario, { focusTab: true });
      }
    });
  });

  $$('[data-demo-target]').forEach(button => button.addEventListener('click', () => selectScenario(button.dataset.demoTarget, { scroll: true })));

  $('#demo-conversation').addEventListener('click', event => {
    const taskButton = event.target.closest('[data-task]');
    if (taskButton) {
      const id = taskButton.dataset.task;
      if (!prepTasks.some(task => task.id === id)) return;
      state.completed.has(id) ? state.completed.delete(id) : state.completed.add(id);
      renderDemo();
      $(`[data-task="${id}"]`).focus({ preventScroll: true });
      return;
    }
    const actionButton = event.target.closest('[data-demo-action]');
    if (!actionButton) return;
    const action = actionButton.dataset.demoAction;
    if (action === 'add-restock') {
      state.restockAdded = true;
      renderDemo();
      $('[data-demo-action="open-draft"]').focus({ preventScroll: true });
      toast('Two items added to the sample Need More list.');
    } else if (action === 'open-draft') {
      state.draftOpen = true;
      renderDemo({ animate: true });
      $('[data-demo-action="back-inventory"]').focus({ preventScroll: true });
    } else if (action === 'back-inventory') {
      state.draftOpen = false;
      renderDemo();
      $('[data-demo-action="open-draft"]').focus({ preventScroll: true });
    }
  });

  $('#reset-demo').addEventListener('click', () => {
    state.completed.clear();
    state.restockAdded = false;
    state.draftOpen = false;
    renderDemo({ animate: true });
    toast('Sample demo reset. Ready for another shift.');
  });

  const menuButton = $('.menu-toggle');
  const mobileNav = $('#mobile-nav');
  function closeMenu({ restoreFocus = false } = {}) {
    mobileNav.hidden = true;
    menuButton.setAttribute('aria-expanded', 'false');
    menuButton.setAttribute('aria-label', 'Open navigation');
    if (restoreFocus) menuButton.focus();
  }
  menuButton.addEventListener('click', () => {
    const expanded = menuButton.getAttribute('aria-expanded') === 'true';
    mobileNav.hidden = expanded;
    menuButton.setAttribute('aria-expanded', String(!expanded));
    menuButton.setAttribute('aria-label', expanded ? 'Open navigation' : 'Close navigation');
  });
  $$('#mobile-nav a').forEach(link => link.addEventListener('click', () => closeMenu()));
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && !mobileNav.hidden) closeMenu({ restoreFocus: true }); });
  document.addEventListener('click', event => { if (!mobileNav.hidden && !event.target.closest('.site-header')) closeMenu(); });
  const mobileMedia = window.matchMedia('(max-width: 720px)');
  mobileMedia.addEventListener('change', event => { if (!event.matches) closeMenu(); });
  $('#copyright-year').textContent = String(new Date().getFullYear());
  renderDemo();
})();
