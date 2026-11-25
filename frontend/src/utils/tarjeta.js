// Tarjeta simulada: valores iniciales y validación en el cliente (el servidor vuelve a validar).
import { fechaCivilHoy } from './formato.js'

export const TARJETA_VACIA = { numero: '', titular: '', expira_mes: '', expira_anio: '', cvv: '', alias: '' }

/**
 * El vencimiento MM/AAAA es un mes civil: la tarjeta vale hasta el último día de ese mes en Bolivia.
 * Se compara año y mes con la fecha de hoy en America/La_Paz, sin convertirlo en un instante del navegador.
 */
export function validarTarjeta(t, ahora = new Date()) {
  const errores = {}
  const digitos = (t.numero || '').replace(/\s/g, '')
  const [anioActual, mesActual] = fechaCivilHoy(ahora).split('-').map(Number)
  const mes = Number(t.expira_mes)
  const anio = Number(t.expira_anio)

  if (!/^\d{13,19}$/.test(digitos)) errores.numero = 'El número debe tener entre 13 y 19 dígitos.'
  else if (!luhnValido(digitos)) errores.numero = 'El número de tarjeta no es válido.'
  if (!/^[\p{L}][\p{L}\s'.-]{2,}$/u.test((t.titular || '').trim())) errores.titular = 'Ingresa el nombre del titular.'
  if (!Number.isInteger(mes) || mes < 1 || mes > 12) errores.expira_mes = 'Mes entre 1 y 12.'
  if (!Number.isInteger(anio) || anio < anioActual || anio > anioActual + 15) errores.expira_anio = 'Año no válido.'
  else if (!errores.expira_mes && anio === anioActual && mes < mesActual) errores.expira_mes = 'La tarjeta está vencida.'
  if (!/^\d{3,4}$/.test(t.cvv || '')) errores.cvv = '3 o 4 dígitos.'

  return errores
}

/** Cuerpo listo para enviar a la API (sin espacios en el número y con números enteros). */
export function tarjetaParaEnviar(t) {
  return { ...t, numero: t.numero.replace(/\s/g, ''), expira_mes: Number(t.expira_mes), expira_anio: Number(t.expira_anio) }
}

function luhnValido(numero) {
  let suma = 0
  numero.split('').reverse().forEach((caracter, i) => {
    let d = Number(caracter)
    if (i % 2 === 1) {
      d *= 2
      if (d > 9) d -= 9
    }
    suma += d
  })
  return suma % 10 === 0
}
