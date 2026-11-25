// Clave de idempotencia del pago asociada al pedido y al cuerpo efectivo (hallazgo 5 de la revisión intermedia).
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { crearRegistroIntentos, cuerpoPago, huellaIntento, tarjetaTrasCargar } from '../src/utils/intentoPago.js'
import { TARJETA_VACIA } from '../src/utils/tarjeta.js'

const PEDIDO = 12
const TARJETA_NUEVA = { numero: '4242 4242 4242 4242', titular: 'Daniela Flores', expira_mes: '12', expira_anio: '2028', cvv: '123', alias: '' }
const SIN_RESPUESTA = { estado: 0, mensaje: 'No se pudo conectar con el servidor.' }

/** Registro con claves predecibles (A, B, C…) para comparar. */
function registro() {
  let n = 0
  return crearRegistroIntentos(() => String.fromCharCode(65 + n++))
}

/** Envía un intento: devuelve la clave usada y lo cierra con la falla dada (o con éxito si es null). */
function enviar(intentos, campos, falla = null, pedidoId = PEDIDO) {
  const huella = huellaIntento(pedidoId, cuerpoPago(campos))
  const clave = intentos.claveDe(huella)
  intentos.terminar(huella, falla)
  return clave
}

test('el cuerpo efectivo solo lleva lo que se envía con el método elegido', () => {
  assert.deepEqual(cuerpoPago({ metodo: 'QR', tarjetaId: '7', tarjeta: TARJETA_NUEVA, guardar: true }), { metodo: 'QR' })
  assert.deepEqual(cuerpoPago({ metodo: 'EFECTIVO', tarjetaId: '7' }), { metodo: 'EFECTIVO' })
  assert.deepEqual(cuerpoPago({ metodo: 'QR', cuotaNumero: 2 }), { metodo: 'QR', cuota_numero: 2 })
  assert.deepEqual(cuerpoPago({ metodo: 'TARJETA', tarjetaId: '7', tarjeta: TARJETA_NUEVA, guardar: true }), { metodo: 'TARJETA', metodo_pago_id: 7 })
  assert.deepEqual(cuerpoPago({ metodo: 'TARJETA', tarjetaId: '', tarjeta: TARJETA_NUEVA, guardar: true }), {
    metodo: 'TARJETA', guardar_tarjeta: true,
    tarjeta: { numero: '4242424242424242', titular: 'Daniela Flores', expira_mes: 12, expira_anio: 2028, cvv: '123', alias: '' },
  })
})

test('reproducción: QR enviado antes de que carguen las tarjetas conserva su clave al reintentar', () => {
  const intentos = registro()
  // 1. Aún no llegó la lista de tarjetas (tarjetaId vacío): se envía QR con la clave A y no hay respuesta.
  const primera = enviar(intentos, { metodo: 'QR', tarjetaId: '', tarjeta: TARJETA_VACIA }, SIN_RESPUESTA)
  // 2. Llega la tarjeta predeterminada (cambia un campo que con QR no se envía) y se reintenta el mismo QR.
  const reintento = enviar(intentos, { metodo: 'QR', tarjetaId: '7', tarjeta: TARJETA_VACIA }, SIN_RESPUESTA)
  assert.equal(primera, 'A')
  assert.equal(reintento, 'A', 'el mismo QR con resultado desconocido debe reutilizar la clave')
  // Tampoco la cambian los datos de una tarjeta nueva escritos y luego abandonados, ni «guardar».
  assert.equal(enviar(intentos, { metodo: 'QR', tarjetaId: '', tarjeta: TARJETA_NUEVA, guardar: true }, { estado: 503 }), 'A')
  assert.equal(intentos.pendientes(), 1)
})

test('409 y 5xx también mantienen la clave; una respuesta conocida la libera', () => {
  const intentos = registro()
  const qr = { metodo: 'QR' }
  assert.equal(enviar(intentos, qr, { estado: 409 }), 'A')
  assert.equal(enviar(intentos, qr, { estado: 500 }), 'A')
  assert.equal(enviar(intentos, qr, null), 'A', 'el intento que por fin responde usa todavía la clave A')
  assert.equal(enviar(intentos, qr, { estado: 422 }), 'B', 'resuelto el anterior, un nuevo intento lleva otra clave')
  assert.equal(enviar(intentos, qr, SIN_RESPUESTA), 'C', 'un 422 también es un resultado conocido')
  assert.equal(intentos.pendientes(), 1)
})

test('cambiar el cuerpo efectivo genera otra clave y volver al anterior recupera la suya', () => {
  const intentos = registro()
  const qr = { metodo: 'QR', cuotaNumero: 1 }
  const guardada7 = { metodo: 'TARJETA', tarjetaId: '7', cuotaNumero: 1 }
  assert.equal(enviar(intentos, qr, SIN_RESPUESTA), 'A')
  assert.equal(enviar(intentos, guardada7, SIN_RESPUESTA), 'B')
  assert.equal(enviar(intentos, { ...guardada7, tarjetaId: '8' }, SIN_RESPUESTA), 'C')
  assert.equal(enviar(intentos, { ...qr, cuotaNumero: 2 }, SIN_RESPUESTA), 'D')
  assert.equal(enviar(intentos, { metodo: 'TARJETA', tarjetaId: '', tarjeta: TARJETA_NUEVA }, SIN_RESPUESTA), 'E')
  assert.equal(enviar(intentos, { metodo: 'TARJETA', tarjetaId: '', tarjeta: { ...TARJETA_NUEVA, cvv: '999' } }, SIN_RESPUESTA), 'F')
  // El primer QR sigue sin resultado conocido: reintentarlo usa su clave original.
  assert.equal(enviar(intentos, qr, SIN_RESPUESTA), 'A')
  // Otro pedido con el mismo cuerpo es otro intento.
  assert.equal(enviar(intentos, qr, SIN_RESPUESTA, 13), 'G')
})

test('la huella no depende del orden de los campos ni de la clave enviada', () => {
  assert.equal(
    huellaIntento(PEDIDO, { metodo: 'TARJETA', metodo_pago_id: 7, cuota_numero: 1, clave_operacion: 'x' }),
    huellaIntento('12', { cuota_numero: 1, metodo_pago_id: 7, metodo: 'TARJETA' }),
  )
})

test('la carga tardía de tarjetas no cambia la tarjeta elegida después de un intento', () => {
  const vigentes = [{ id: 7, predeterminado: false }, { id: 9, predeterminado: true }]
  // Sin interacción del cliente, se propone la predeterminada (o la primera).
  assert.equal(tarjetaTrasCargar(vigentes, '', false), '9')
  assert.equal(tarjetaTrasCargar([{ id: 7 }], '', false), '7')
  assert.equal(tarjetaTrasCargar([], '', false), '')
  // Reproducción: el cliente pagó con una tarjeta nueva (tarjetaId vacío) antes de que llegara la lista y no hubo
  // respuesta. Al llegar las tarjetas no debe pasar a la guardada: el reintento enviaría otro cuerpo y otra clave.
  const intentos = registro()
  const nueva = { metodo: 'TARJETA', tarjeta: TARJETA_NUEVA }
  const primera = enviar(intentos, { ...nueva, tarjetaId: '' }, SIN_RESPUESTA)
  const seleccion = tarjetaTrasCargar(vigentes, '', true)
  assert.equal(seleccion, '')
  assert.equal(enviar(intentos, { ...nueva, tarjetaId: seleccion }, SIN_RESPUESTA), primera)
  // Tampoco cambia una tarjeta guardada que el cliente ya eligió a mano.
  assert.equal(tarjetaTrasCargar(vigentes, '7', true), '7')
})

test('el panel de pago usa el registro de intentos y no borra la clave al cambiar campos', () => {
  const panel = readFileSync(new URL('../src/components/comercio/PanelPago.jsx', import.meta.url), 'utf8')
  assert.match(panel, /cuerpoPago\(\{ metodo, cuotaNumero: cuota\?\.numero \?\? null, tarjetaId, tarjeta, guardar \}\)/)
  assert.match(panel, /intentosPago\.claveDe\(intento\)/)
  assert.match(panel, /intentosPago\.terminar\(intento\)/)
  assert.match(panel, /intentosPago\.terminar\(intento, falla\)/)
  assert.doesNotMatch(panel, /claveOperacion\.current = null/)
  assert.doesNotMatch(panel, /datosIntento/)
  // La lista de tarjetas que llega tarde respeta la elección del cliente (y su intento pendiente).
  assert.match(panel, /setTarjetaId\(\(actual\) => tarjetaTrasCargar\(vigentes, actual, eligioTarjeta\.current\)\)/)
  assert.match(panel, /const pagar = async \(\) => \{\s*if \(enCurso\.current\) return\s*eligioTarjeta\.current = true/)
})
