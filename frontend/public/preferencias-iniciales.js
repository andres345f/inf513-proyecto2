/* Aplicar la preferencia antes del primer dibujo. */
(() => {
  let preferencias = {}
  try { preferencias = JSON.parse(localStorage.getItem('feder:preferencias')) || {} } catch { /* El almacenamiento es opcional. */ }
  const { dataset } = document.documentElement
  const hora = new Date().getHours()
  dataset.tema = ['ninos', 'jovenes', 'adultos'].includes(preferencias.tema) ? preferencias.tema : 'adultos'
  dataset.modo = ['dia', 'noche'].includes(preferencias.modo) ? preferencias.modo : (hora >= 18 || hora < 6 ? 'noche' : 'dia')
  dataset.contraste = preferencias.contraste === 'alto' ? 'alto' : 'normal'
  dataset.escala = [90, 100, 110, 120, 130, 140].includes(preferencias.escala) ? String(preferencias.escala) : '100'
})()
