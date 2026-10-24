
function showToast(msg, duration = 3000) {
  const t = document.getElementById('toast');
  if (!t) return;
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), duration);
}


document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
      const target = document.querySelector(a.getAttribute('href'));
      if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth' }); }
    });
  });
});


async function submitContact() {
  const name  = document.getElementById('cName');
  const email = document.getElementById('cEmail');
  const msg   = document.getElementById('cMsg');

  let ok = true;

  if (!name || !name.value.trim()) {
    if (name) name.classList.add('invalid'); ok = false;
  } else { name.classList.remove('invalid'); }

  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  if (!email || !emailRegex.test(email.value.trim())) {
    if (email) email.classList.add('invalid'); ok = false;
  } else { email.classList.remove('invalid'); }

  if (!msg || !msg.value.trim()) {
    if (msg) msg.classList.add('invalid'); ok = false;
  } else { msg.classList.remove('invalid'); }

  if (!ok) { showToast('Veuillez remplir tous les champs correctement.'); return; }

  const btn = document.querySelector('#contact .btn-primary');
  btn.disabled = true; btn.textContent = ' Envoi…';

  try {
    const fd = new FormData();
    fd.append('action',       'contact');
    fd.append('nom_complet',  name.value.trim());
    fd.append('email',        email.value.trim());
    fd.append('message',      msg.value.trim());

    const r = await fetch('index.php', { method: 'POST', body: fd });
    const d = await r.json();

    if (d.ok) {
      const success = document.getElementById('contactSuccess');
      if (success) success.style.display = 'block';
      showToast(' Message envoyé avec succès !');
      name.value = ''; email.value = ''; msg.value = '';
      setTimeout(() => { if (success) success.style.display = 'none'; }, 3000);
    } else {
      showToast(' Erreur : ' + d.msg);
    }
  } catch(e) {
    showToast(' Erreur réseau, réessayez.');
  } finally {
    btn.disabled = false; btn.textContent = 'Envoyer le message';
  }
}