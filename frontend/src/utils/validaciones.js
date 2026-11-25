// Validaciones del lado del cliente. Reflejan las reglas del servidor y devuelven mensajes en español.
// Cada validador recibe el valor y devuelve un mensaje de error o null.

const SOLO_LETRAS = /^[\p{L}][\p{L}\s'.-]*$/u
const CORREO = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export const requerido = (mensaje) => (valor) =>
  valor === undefined || valor === null || String(valor).trim() === '' ? mensaje : null

export const correo = () => (valor) =>
  valor && !CORREO.test(String(valor).trim()) ? 'Ingresa un correo electrónico válido, por ejemplo nombre@correo.com.' : null

export const soloLetras = (campo) => (valor) =>
  valor && !SOLO_LETRAS.test(String(valor).trim()) ? `${campo} solo pueden contener letras y espacios.` : null

export const longitudMaxima = (maximo, campo) => (valor) =>
  valor && String(valor).length > maximo ? `${campo} admite como máximo ${maximo} caracteres.` : null

export const enteroEntre = (minimo, maximo, mensaje) => (valor) => {
  if (valor === '' || valor === null || valor === undefined) return null
  const texto = String(valor).trim()
  if (!/^\d+$/.test(texto)) return mensaje
  const numero = Number(texto)
  return numero < minimo || numero > maximo ? mensaje : null
}

export const patron = (expresion, mensaje) => (valor) =>
  valor && !expresion.test(String(valor).trim()) ? mensaje : null

/** Teléfono boliviano almacenado por el backend: ocho dígitos, con prefijo entre 2 y 7. */
export const telefonoBoliviano = () => patron(
  /^[2-7]\d{7}$/,
  'Ingresa un teléfono boliviano de 8 dígitos (por ejemplo 70012345).',
)

export const contrasenaSegura = () => (valor) => {
  if (!valor) return null
  if (valor.length < 8) return 'La contraseña debe tener al menos 8 caracteres.'
  if (!/[A-Za-z]/.test(valor) || !/\d/.test(valor)) return 'La contraseña debe combinar letras y números.'
  return null
}

export const igualA = (otroCampo, mensaje) => (valor, valores) => (valor !== valores[otroCampo] ? mensaje : null)

export const aceptado = (mensaje) => (valor) => (valor ? null : mensaje)

export const longitudMinima = (minimo, mensaje) => (valor) =>
  valor && String(valor).trim().length < minimo ? mensaje : null

/** Obligatorio solo cuando se cumple la condición sobre el resto de los valores. */
export const requeridoSi = (condicion, mensaje) => (valor, valores) =>
  condicion(valores) ? requerido(mensaje)(valor) : null

// Los <input type="number"> entregan texto ('' si está vacío). Se acepta coma decimal.
const aNumero = (valor) => Number(String(valor).trim().replace(',', '.'))
const vacio = (valor) => valor === undefined || valor === null || String(valor).trim() === ''

/**
 * Número con límites. Opciones: mayorQue, minimo, maximo, decimales (máximo de decimales), entero.
 * Un valor vacío pasa: combínalo con requerido() cuando el campo sea obligatorio.
 */
export const numero = (opciones, mensaje) => (valor) => {
  if (vacio(valor)) return null
  const texto = String(valor).trim().replace(',', '.')
  if (!/^-?\d+(\.\d+)?$/.test(texto)) return mensaje
  const n = aNumero(valor)
  const { mayorQue, minimo, maximo, decimales, entero } = opciones
  if (mayorQue !== undefined && !(n > mayorQue)) return mensaje
  if (minimo !== undefined && n < minimo) return mensaje
  if (maximo !== undefined && n > maximo) return mensaje
  if (entero && !Number.isInteger(n)) return mensaje
  if (decimales !== undefined && (texto.split('.')[1] ?? '').length > decimales) return mensaje
  return null
}

/** El número no puede superar al de otro campo del mismo formulario. */
export const noMayorQueCampo = (otroCampo, mensaje) => (valor, valores) =>
  vacio(valor) || vacio(valores[otroCampo]) || aNumero(valor) <= aNumero(valores[otroCampo]) ? null : mensaje

/** Fecha civil AAAA-MM-DD válida (lo que entrega un <input type="date">). */
export const fechaValida = (mensaje) => (valor) => {
  if (vacio(valor)) return null
  const partes = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(valor))
  if (!partes) return mensaje
  const [, a, m, d] = partes.map(Number)
  const fecha = new Date(Date.UTC(a, m - 1, d))
  return fecha.getUTCFullYear() === a && fecha.getUTCMonth() === m - 1 && fecha.getUTCDate() === d ? null : mensaje
}

/** La fecha no puede ser anterior a la de otro campo (las dos en AAAA-MM-DD se comparan como texto). */
export const noAnteriorA = (otroCampo, mensaje) => (valor, valores) =>
  vacio(valor) || vacio(valores[otroCampo]) || String(valor) >= String(valores[otroCampo]) ? null : mensaje

/**
 * Atributos de accesibilidad para un control con posible error:
 * <input {...atributosError(errores, 'campo', 'id-del-mensaje')} />
 */
export function atributosError(errores, campo, idMensaje) {
  const error = errores?.[campo]
  return { 'aria-invalid': error ? true : undefined, 'aria-describedby': error ? idMensaje : undefined }
}

/** Convierte los errores 422 de la API ({ campo: [mensajes] }) en { campo: primer mensaje }. */
export function erroresDeApi(falla) {
  return Object.fromEntries(Object.entries(falla?.errores || {}).map(([campo, mensajes]) => [campo, Array.isArray(mensajes) ? mensajes[0] : mensajes]))
}

/** Lleva el foco al primer control marcado como inválido dentro de un formulario. */
export function enfocarPrimerError(formulario) {
  if (!formulario) return
  window.requestAnimationFrame(() => formulario.querySelector('[aria-invalid="true"]')?.focus())
}

/** Aplica las reglas { campo: [validador, ...] } y devuelve { campo: mensaje } con el primer error de cada campo. */
export function validar(valores, reglas) {
  const errores = {}
  for (const [campo, validadores] of Object.entries(reglas)) {
    for (const validador of validadores) {
      const error = validador(valores[campo], valores)
      if (error) {
        errores[campo] = error
        break
      }
    }
  }
  return errores
}
