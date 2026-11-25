// Intentos de pago y su clave de idempotencia (clave_operacion).
//
// La clave pertenece al intento, es decir, al pedido y al cuerpo que de verdad se envía (método, tarjeta guardada
// o datos de la tarjeta nueva, cuota). Mientras el resultado de ese intento sea desconocido (sin respuesta, 409 o
// 5xx), volver a enviar el mismo cuerpo reutiliza la misma clave, aunque entretanto cambien campos que no se envían
// (por ejemplo, la tarjeta elegida cuando se paga por QR) o termine de cargar la lista de tarjetas.
// Se genera una clave nueva solo si cambia el cuerpo efectivo o si el intento terminó con un resultado conocido.
import { nuevaClaveOperacion, reintentarConMismaClave } from './claveOperacion.js'
import { tarjetaParaEnviar } from './tarjeta.js'

/** Cuerpo efectivo de POST /mis-pedidos/{id}/pagar, sin la clave: solo lo que se envía con el método elegido. */
export function cuerpoPago({ metodo, cuotaNumero = null, tarjetaId = '', tarjeta = null, guardar = false }) {
  const cuerpo = { metodo }
  if (cuotaNumero !== null && cuotaNumero !== undefined) cuerpo.cuota_numero = cuotaNumero
  if (metodo === 'TARJETA') {
    if (tarjetaId) {
      cuerpo.metodo_pago_id = Number(tarjetaId)
    } else {
      cuerpo.tarjeta = tarjetaParaEnviar(tarjeta)
      cuerpo.guardar_tarjeta = Boolean(guardar)
    }
  }
  return cuerpo
}

function ordenado(valor) {
  if (Array.isArray(valor)) return valor.map(ordenado)
  if (valor && typeof valor === 'object') {
    return Object.fromEntries(Object.keys(valor).sort().map((clave) => [clave, ordenado(valor[clave])]))
  }
  return valor
}

/** Identifica el intento: el pedido y su cuerpo efectivo (sin importar el orden de los campos). */
export function huellaIntento(pedidoId, cuerpo) {
  const resto = { ...cuerpo }
  delete resto.clave_operacion
  return JSON.stringify([Number(pedidoId), ordenado(resto)])
}

/**
 * Registro de intentos cuyo resultado todavía no se conoce. Solo vive en memoria (no se guarda en el navegador).
 * - claveDe(huella): la clave del intento; la misma mientras siga sin resultado conocido.
 * - terminar(huella, falla): con respuesta del servidor (éxito o rechazo con 4xx distinto de 409) el intento queda
 *   resuelto y se olvida su clave; sin respuesta, 409 o 5xx se conserva para el reintento.
 */
export function crearRegistroIntentos(generarClave = nuevaClaveOperacion) {
  const pendientes = new Map()
  return {
    claveDe(huella) {
      if (!pendientes.has(huella)) pendientes.set(huella, generarClave())
      return pendientes.get(huella)
    },
    terminar(huella, falla = null) {
      if (falla === null || !reintentarConMismaClave(falla)) pendientes.delete(huella)
    },
    pendientes() {
      return pendientes.size
    },
  }
}

/**
 * Tarjeta seleccionada cuando termina de cargar la lista de tarjetas guardadas. Solo se propone la predeterminada
 * (o la primera) si el cliente todavía no eligió nada ni envió un intento; si ya lo hizo, se respeta su elección,
 * así un reintento envía el mismo cuerpo y reutiliza la clave del intento pendiente.
 */
export function tarjetaTrasCargar(vigentes, actual, eligioElCliente) {
  if (eligioElCliente) return actual
  return String(vigentes.find((m) => m.predeterminado)?.id ?? vigentes[0]?.id ?? '')
}

/** Registro compartido de la aplicación: sobrevive a que el panel de pago se desmonte y se vuelva a montar. */
export const intentosPago = crearRegistroIntentos()
