// Datos de los gráficos de ingresos del panel de negocio. Los importes son NETOS (cobrado − reintegrado) y pueden
// ser negativos: un periodo o un medio con más reintegros que cobros tuvo movimientos, no está «sin registros».
import { formatearMoneda } from './formato.js'

/**
 * Dominio del eje de importes netos, para que un neto negativo se vea bajo la línea base. Va junto con
 * <ReferenceLine … ifOverflow="extendDomain" /> en 0, que mete el cero aunque todos los netos sean negativos.
 * El extremo «auto» hace que Recharts redondee las marcas (−80, −60, …, 0); sin «auto» quedan irregulares y
 * terminan en el importe exacto (−76, −56, …), y en un periodo mixto el cero se queda sin marca.
 */
export const DOMINIO_CON_CERO = [(minimo) => Math.min(0, minimo), 'auto']

/** Una fila por medio de pago con movimientos en el periodo (cobros o reintegros), sin descartar netos ≤ 0. */
export function filasPorMetodo(pagosPorMetodo = [], etiquetas = {}) {
  return (pagosPorMetodo ?? []).map((fila) => ({
    metodo: fila.metodo,
    nombre: etiquetas[fila.metodo] ?? fila.metodo,
    monto: Number(fila.monto) || 0,
    cobrado: Number(fila.cobrado) || 0,
    reintegrado: Number(fila.reintegrado) || 0,
    cantidad: Number(fila.cantidad) || 0,
  }))
}

/** Texto de cabecera: cuántos medios tuvieron movimientos; «Sin registros» solo si no hubo ninguno. */
export function metaPorMetodo(filas) {
  if (filas.length === 0) return 'Sin registros'
  return filas.length === 1 ? '1 medio con movimientos' : `${filas.length} medios con movimientos`
}

/** Resumen para lectores de pantalla: neto, cobrado y reintegrado de cada medio. */
export function resumenPorMetodo(filas) {
  return filas.map((f) => `${f.nombre}: neto ${formatearMoneda(f.monto)} (cobrado ${formatearMoneda(f.cobrado)}, reintegrado ${formatearMoneda(f.reintegrado)})`).join('; ')
}

/** «1 mes con registros» / «N meses con registros» / «Sin registros». */
export function metaPorMes(meses) {
  if (meses.length === 0) return 'Sin registros'
  return meses.length === 1 ? '1 mes con registros' : `${meses.length} meses con registros`
}

/** Signo de un importe para colorearlo (positivo, negativo o cero). */
export function signoImporte(valor) {
  const numero = Number(valor) || 0
  if (numero < 0) return 'negativo'
  return numero > 0 ? 'positivo' : 'cero'
}
