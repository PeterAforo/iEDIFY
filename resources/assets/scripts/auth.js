// Password visibility toggles — [data-password-toggle="<input id>"] buttons
// swap the input type and their eye/eye-off icons.
export const initAuth = () => {
  document.querySelectorAll('[data-password-toggle]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.passwordToggle);
      if (!input) return;
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', String(show));
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      btn.querySelectorAll('svg').forEach((icon) => { icon.hidden = !icon.hidden; });
      input.focus();
    });
  });
};
