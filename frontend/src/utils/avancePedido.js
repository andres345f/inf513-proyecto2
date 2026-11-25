// Avance y cancelación de pedidos por el personal (detalle del pedido y lista de entregas).
import { formatearMoneda } from './formato.js'

/**
 * Cuerpo de POST /gestion/{pedidos|entregas}/{id}/avanzar. Siempre lleva «hacia»: el estado al que la pantalla
 * ofrecía avanzar. Si otra persona ya movió el pedido, el servidor responde 409 y no da un paso distinto.
 */
export function cuerpoAvance(hacia, { responsable = '', telefono = '', efectivoConfirmado = false } = {}) {
  if (!hacia) throw new Error('Falta el estado de destino del avance.')
  return {
    hacia,
    responsable_entrega: responsable.trim() || undefined,
    telefono_responsable: telefono.trim() || undefined,
    efectivo_confirmado: Boolean(efectivoConfirmado),
  }
}

/** 409: el pedido cambió desde que se abrió la pantalla. Hay que volver a cargarlo antes de seguir. */
export function esConflicto(falla) {
  return falla?.estado === 409
}

/**
 * Mensaje en español para un 409. La pantalla se actualiza sola, así que se quita el «Recarga la página» del
 * servidor y se dice que ya se muestra el estado actual.
 */
export function mensajeConflicto(falla) {
  const base = String(falla?.mensaje ?? '')
    .replace(/\s*Recarga la página( antes de volver a avanzarlo)?\.?\s*$/u, '')
    .replace(/^No se pudo completar la solicitud \(error 409\)\.?$/u, '')
    .trim()
  const motivo = base || 'Otra persona cambió este pedido mientras lo veías.'
  return `${/[.!?]$/u.test(motivo) ? motivo : `${motivo}.`} No se aplicó ningún cambio: actualizamos la pantalla con el estado actual del pedido.`
}

/** El mismo aviso en una lista de pedidos: antepone el código solo si el mensaje no lo nombra ya. */
export function avisoConflictoEnLista(codigo, falla) {
  const texto = mensajeConflicto(falla)
  return codigo && !texto.includes(codigo) ? `${codigo}: ${texto}` : texto
}

/** Aviso previo a cancelar: el reintegro que registrará el servidor (acciones.reintegro_al_cancelar). */
export function avisoReintegro(acciones) {
  const monto = Number(acciones?.reintegro_al_cancelar ?? 0)
  if (!(monto > 0)) return null
  return `Este pedido tiene pagos confirmados por ${formatearMoneda(monto)}. Al cancelarlo se registrará un reintegro de ${formatearMoneda(monto)} a favor del cliente.`
}

/** Resultado de la cancelación: lo reintegrado es lo cobrado del pedido ya cancelado. */
export function resultadoCancelacion(actualizado, previsto = 0) {
  const monto = Number(actualizado?.pagado ?? previsto) || 0
  return monto > 0
    ? `Pedido cancelado y stock liberado. Se registró un reintegro de ${formatearMoneda(monto)} a favor del cliente.`
    : 'Pedido cancelado y stock liberado.'
}

/** Textos de la acción «abastecer»: un encargo en espera o un pedido confirmado que perdió parte de su reserva. */
export function textosAbastecer(pedido) {
  const encargo = pedido?.estado === 'ESPERANDO_ABASTECIMIENTO'
  return encargo
    ? { boton: 'Abastecer encargo', exito: 'Encargo abastecido: el stock quedó reservado.' }
    : { boton: 'Reservar stock faltante', exito: 'Stock reservado de nuevo en lotes vigentes.' }
}

/** Aviso de falta de stock reservado (acciones.falta_stock) en un pedido confirmado o en preparación. */
export function avisoFaltaStock(acciones) {
  if (!acciones?.falta_stock) return null
  return 'A este pedido le falta stock reservado en lotes vigentes (un lote venció o se bloqueó). '
    + 'No se podrá preparar ni despachar hasta reservar lo que falta con «Reservar stock faltante»; si no hay stock, cancélalo.'
}
