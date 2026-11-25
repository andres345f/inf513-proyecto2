// Opciones de apariencia y accesibilidad que el usuario puede elegir.

export const TEMAS = [
  { valor: 'ninos', etiqueta: 'Niños' },
  { valor: 'jovenes', etiqueta: 'Jóvenes' },
  { valor: 'adultos', etiqueta: 'Adultos' },
]

export const MODOS = [
  { valor: 'auto', etiqueta: 'Automático' },
  { valor: 'dia', etiqueta: 'Día' },
  { valor: 'noche', etiqueta: 'Noche' },
]

export const ESCALAS = [90, 100, 110, 120, 130, 140]

export const PREFERENCIAS_INICIALES = { tema: 'adultos', modo: 'auto', contraste: 'normal', escala: 100 }

/** Modo automático: noche entre las 18:00 y las 06:00 (hora del navegador). */
export function modoSegunHora(fecha = new Date()) {
  const hora = fecha.getHours()
  return hora >= 18 || hora < 6 ? 'noche' : 'dia'
}
