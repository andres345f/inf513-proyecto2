// Formatos bolivianos: fechas dd/mm/aaaa, moneda en bolivianos (Bs) y números con coma decimal.

const LOCALE = 'es-BO'

/**
 * Fechas: la API envía dos clases de valores y no se tratan igual.
 * - Fecha civil «AAAA-MM-DD» (columnas date: vencimientos, cuotas, producciones, rango del panel).
 *   No tiene hora ni zona: se muestra tal cual. `new Date('AAAA-MM-DD')` la tomaría como
 *   medianoche UTC y en Bolivia (UTC−4) saldría un día antes.
 * - Instante «AAAA-MM-DD HH:MM:SS-04» o ISO con Z/desplazamiento (columnas timestamptz).
 *   Se presenta siempre en la hora de Bolivia, sin depender de la zona del navegador.
 */
export const ZONA_HORARIA = 'America/La_Paz'
// Bolivia usa UTC−4 todo el año: es el desplazamiento de una marca que llega sin zona.
const DESPLAZAMIENTO_BOLIVIA = '-04:00'
const FECHA_CIVIL = /^(\d{4})-(\d{2})-(\d{2})$/
const INSTANTE = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(?::(\d{2})(?:\.(\d+))?)?\s*(Z|[+-]\d{2}(?::?\d{2})?)?$/i

const FORMATO_FECHA = new Intl.DateTimeFormat(LOCALE, { timeZone: ZONA_HORARIA, day: '2-digit', month: '2-digit', year: 'numeric' })
const FORMATO_FECHA_HORA = new Intl.DateTimeFormat(LOCALE, {
  timeZone: ZONA_HORARIA, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
})
// en-CA produce AAAA-MM-DD, el formato de los <input type="date"> y de los filtros de la API.
const FORMATO_CIVIL = new Intl.DateTimeFormat('en-CA', { timeZone: ZONA_HORARIA, year: 'numeric', month: '2-digit', day: '2-digit' })

/** ¿El valor es una fecha civil AAAA-MM-DD válida (sin hora)? */
export function esFechaCivil(valor) {
  if (typeof valor !== 'string') return false
  const partes = FECHA_CIVIL.exec(valor.trim())
  if (!partes) return false
  const [, anio, mes, dia] = partes.map(Number)
  const fecha = new Date(Date.UTC(anio, mes - 1, dia))
  return fecha.getUTCFullYear() === anio && fecha.getUTCMonth() === mes - 1 && fecha.getUTCDate() === dia
}

/** Convierte un instante de la API en Date. Sin desplazamiento se asume la hora de Bolivia. */
export function aInstante(valor) {
  if (valor instanceof Date) return valor
  if (typeof valor === 'number') return new Date(valor)
  const texto = String(valor ?? '').trim()
  const partes = INSTANTE.exec(texto)
  if (!partes) return new Date(Number.NaN)
  const [, fecha, horaMinuto, segundos = '00', fraccion = '', zona] = partes
  let desplazamiento = (zona || DESPLAZAMIENTO_BOLIVIA).toUpperCase()
  if (/^[+-]\d{2}$/.test(desplazamiento)) desplazamiento += ':00'
  else if (/^[+-]\d{4}$/.test(desplazamiento)) desplazamiento = `${desplazamiento.slice(0, 3)}:${desplazamiento.slice(3)}`
  const milisegundos = fraccion ? `.${fraccion.slice(0, 3).padEnd(3, '0')}` : ''
  return new Date(`${fecha}T${horaMinuto}:${segundos}${milisegundos}${desplazamiento}`)
}

function formatearCivil(texto) {
  const [anio, mes, dia] = texto.trim().split('-')
  return `${dia}/${mes}/${anio}`
}

/** dd/mm/aaaa. Fecha civil tal cual; un instante, con el día que corresponde en Bolivia. */
export function formatearFecha(valor) {
  if (!valor) return '—'
  if (esFechaCivil(valor)) return formatearCivil(valor)
  const instante = aInstante(valor)
  return Number.isNaN(instante.getTime()) ? '—' : FORMATO_FECHA.format(instante)
}

/** dd/mm/aaaa, hh:mm en hora de Bolivia. Una fecha civil no tiene hora: se muestra solo la fecha. */
export function formatearFechaHora(valor) {
  if (!valor) return '—'
  if (esFechaCivil(valor)) return formatearCivil(valor)
  const instante = aInstante(valor)
  return Number.isNaN(instante.getTime()) ? '—' : FORMATO_FECHA_HORA.format(instante)
}

/** Fecha civil de hoy (AAAA-MM-DD) en el calendario de Bolivia. */
export function fechaCivilHoy(ahora = new Date()) {
  return FORMATO_CIVIL.format(ahora)
}

/** Suma (o resta) días a una fecha civil AAAA-MM-DD sin pasar por la zona del navegador. */
export function sumarDiasCivil(fecha, dias) {
  const [anio, mes, dia] = fecha.split('-').map(Number)
  const resultado = new Date(Date.UTC(anio, mes - 1, dia + dias))
  return resultado.toISOString().slice(0, 10)
}

export function formatearMoneda(valor) {
  const numero = new Intl.NumberFormat(LOCALE, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(valor) || 0)
  return `Bs ${numero}`
}

export function formatearNumero(valor) {
  return new Intl.NumberFormat(LOCALE).format(Number(valor) || 0)
}

/** Nombre legible de un rol (los códigos de la base están en mayúsculas). */
export function nombreRol(codigo) {
  const nombres = {
    ADMINISTRADOR: 'Administrador',
    PERSONAL_FEDER: 'Personal de FEDER',
    PRODUCTOR: 'Productor',
    CLIENTE: 'Cliente',
    VISITANTE: 'Visitante',
  }
  return nombres[codigo] || codigo
}
