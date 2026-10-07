document.querySelectorAll('[data-crc-toggle-secret]').forEach((button) => {
  button.addEventListener('click', () => {
    const input = document.getElementById(button.getAttribute('aria-controls'))
    if (!input) return
    const visible = input.type === 'text'
    input.type = visible ? 'password' : 'text'
    button.setAttribute('aria-pressed', String(!visible))
    button.textContent = visible ? 'Afficher' : 'Masquer'
    input.focus()
  })
})
