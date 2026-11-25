// Avance de pedidos por el personal: el cuerpo siempre lleva «hacia» (hallazgo 1 de la revisión intermedia),
// el 409 se explica en español y los avisos usan acciones.reintegro_al_cancelar y acciones.falta_stock del backend.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import {
  avisoConflictoEnLista, avisoFaltaStock, avisoReintegro, cuerpoAvance, esConflicto, mensajeConflicto, resultadoCancelacion,
  textosAbastecer,
} from '../src/utils/avancePedido.js'

const fuente = (ruta) => readFileSync(new URL(`../src/${ruta}`, import.meta.url), 'utf8')

test('el cuerpo del avance lleva siempre el estado que ofrecía la pantalla', () => {
  assert.deepEqual(cuerpoAvance('EN_PREPARACION'), {
    hacia: 'EN_PREPARACION', responsable_entrega: undefined, telefono_responsable: undefined, efectivo_confirmado: false,
  })
  assert.deepEqual(cuerpoAvance('DESPACHADO', { responsable: '  Juan Pérez ', telefono: ' 70011223 ' }), {
    hacia: 'DESPACHADO', responsable_entrega: 'Juan Pérez', telefono_responsable: '70011223', efectivo_confirmado: false,
  })
  assert.equal(cuerpoAvance('ENTREGADO', { efectivoConfirmado: true }).efectivo_confirmado, true)
  // Sin destino no hay avance posible: nunca se envía un avance «a ciegas».
  assert.throws(() => cuerpoAvance(null))
  assert.throws(() => cuerpoAvance(undefined))
})

test('las dos pantallas de avance envían el destino que mostraban', () => {
  const detalle = fuente('pages/gestion/GestionPedidoDetallePagina.jsx')
  const entregas = fuente('pages/gestion/GestionEntregasPagina.jsx')
  // Se fija el destino de la vista antes de abrir el diálogo; solo confirmar despacha el mismo cuerpo.
  assert.match(detalle, /const hacia = p\.acciones\.avanzar\s+const datos = cuerpoAvance\(hacia,/)
  assert.match(detalle, /alConfirmar: \(\) => ejecutar\('avanzar', \(\) => gestionApi\.avanzar\(p\.id, datos\)/)
  assert.match(entregas, /const datos = cuerpoAvance\(entrega\.avanzar_a,/)
  assert.match(entregas, /alConfirmar: \(\) => registrarAvance\(entrega, datos\)/)
  assert.match(entregas, /registrarAvance = async \(entrega, datos\)[\s\S]*?gestionApi\.avanzarEntrega\(pedidoId, datos\)/)
  // Ninguna otra llamada de avance arma su cuerpo a mano.
  assert.equal((detalle.match(/gestionApi\.avanzar\(/g) ?? []).length, 1)
  assert.equal((entregas.match(/gestionApi\.avanzarEntrega\(/g) ?? []).length, 1)
  // Ante un 409 cada pantalla vuelve a cargar los datos.
  assert.match(detalle, /esConflicto\(falla\)[\s\S]{0,300}pedido\.recargar\(\)/)
  assert.match(entregas, /esConflicto\(falla\)[\s\S]{0,300}lista\.recargar\(\)/)
})

test('un 409 se explica en español sin pedir recargar a mano', () => {
  assert.equal(esConflicto({ estado: 409 }), true)
  assert.equal(esConflicto({ estado: 422 }), false)
  assert.equal(esConflicto({ estado: 0 }), false)
  assert.equal(esConflicto(undefined), false)

  assert.equal(
    mensajeConflicto({ estado: 409, mensaje: 'El pedido PED-2026-0010 ya está «En preparación»: alguien lo actualizó antes. Recarga la página.' }),
    'El pedido PED-2026-0010 ya está «En preparación»: alguien lo actualizó antes. No se aplicó ningún cambio: actualizamos la pantalla con el estado actual del pedido.',
  )
  assert.equal(
    mensajeConflicto({ estado: 409, mensaje: 'El pedido acaba de cambiar de estado. Recarga la página antes de volver a avanzarlo.' }),
    'El pedido acaba de cambiar de estado. No se aplicó ningún cambio: actualizamos la pantalla con el estado actual del pedido.',
  )
  // Sin mensaje del servidor (o con el genérico del cliente HTTP) se da uno propio.
  for (const falla of [{ estado: 409 }, { estado: 409, mensaje: 'No se pudo completar la solicitud (error 409).' }]) {
    assert.equal(mensajeConflicto(falla),
      'Otra persona cambió este pedido mientras lo veías. No se aplicó ningún cambio: actualizamos la pantalla con el estado actual del pedido.')
  }
  // Un 409 que no habla de recargar (falta stock) se conserva entero.
  assert.match(mensajeConflicto({ estado: 409, mensaje: 'Usa «Abastecer» para reservar lo que falta o cancela el pedido.' }),
    /^Usa «Abastecer» para reservar lo que falta o cancela el pedido\. No se aplicó/)
})

test('en la lista de entregas el aviso nombra el pedido una sola vez', () => {
  const conCodigoEnElMensaje = { estado: 409, mensaje: 'El pedido PED-2026-0009 ya está «Entregado»: alguien lo actualizó antes. Recarga la página.' }
  assert.equal(avisoConflictoEnLista('PED-2026-0009', conCodigoEnElMensaje),
    'El pedido PED-2026-0009 ya está «Entregado»: alguien lo actualizó antes. No se aplicó ningún cambio: actualizamos la pantalla con el estado actual del pedido.')
  // Si el servidor no lo nombra, se antepone el código para saber de qué fila se trata.
  assert.equal(avisoConflictoEnLista('PED-2026-0009', { estado: 409, mensaje: 'El pedido acaba de cambiar de estado. Recarga la página antes de volver a avanzarlo.' }),
    'PED-2026-0009: El pedido acaba de cambiar de estado. No se aplicó ningún cambio: actualizamos la pantalla con el estado actual del pedido.')
  assert.match(fuente('pages/gestion/GestionEntregasPagina.jsx'), /avisoConflictoEnLista\(entrega\.pedido\.codigo, falla\)/)
})

test('el aviso y el resultado de cancelar usan el reintegro que calcula el backend', () => {
  assert.equal(avisoReintegro({ reintegro_al_cancelar: 218 }),
    'Este pedido tiene pagos confirmados por Bs 218,00. Al cancelarlo se registrará un reintegro de Bs 218,00 a favor del cliente.')
  assert.equal(avisoReintegro({ reintegro_al_cancelar: 0 }), null)
  assert.equal(avisoReintegro({}), null)
  assert.equal(avisoReintegro(undefined), null)

  assert.equal(resultadoCancelacion({ estado: 'CANCELADO', pagado: 106 }, 106),
    'Pedido cancelado y stock liberado. Se registró un reintegro de Bs 106,00 a favor del cliente.')
  assert.equal(resultadoCancelacion({ estado: 'CANCELADO', pagado: 0 }, 0), 'Pedido cancelado y stock liberado.')
  // Si entre la lectura y la cancelación entró otro cobro, manda lo que devuelve el servidor.
  assert.match(resultadoCancelacion({ estado: 'CANCELADO', pagado: 300 }, 200), /Bs 300,00/)
})

test('falta de stock: aviso y textos de la acción según el estado', () => {
  assert.match(avisoFaltaStock({ falta_stock: true }), /le falta stock reservado en lotes vigentes/)
  assert.equal(avisoFaltaStock({ falta_stock: false }), null)
  assert.equal(avisoFaltaStock(undefined), null)
  assert.deepEqual(textosAbastecer({ estado: 'ESPERANDO_ABASTECIMIENTO' }),
    { boton: 'Abastecer encargo', exito: 'Encargo abastecido: el stock quedó reservado.' })
  assert.equal(textosAbastecer({ estado: 'CONFIRMADO' }).boton, 'Reservar stock faltante')
  assert.equal(textosAbastecer({ estado: 'EN_PREPARACION' }).exito, 'Stock reservado de nuevo en lotes vigentes.')
})
